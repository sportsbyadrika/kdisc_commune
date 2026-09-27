<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Services\Kyc\AadhaarVault;

/**
 * `php bin/console app:check` — a go-live / after-upgrade health report. Each check returns
 * ['group', 'name', 'status' => ok|warn|fail, 'detail']. FAIL = the app is unsafe or broken as configured;
 * WARN = works, but not how production should run.
 */
final class EnvironmentCheck
{
    public const EXTENSIONS = ['pdo_mysql', 'sodium', 'mbstring', 'intl', 'gd', 'zip', 'fileinfo', 'dom', 'xml', 'xmlreader', 'xmlwriter', 'simplexml', 'zlib', 'iconv', 'ctype', 'openssl', 'json'];

    public const WRITABLE = ['storage/logs', 'storage/sessions', 'storage/cache', 'storage/uploads', 'storage/pdf', 'storage/exports', 'storage/imports', 'storage/mail', 'public/media/uploads'];

    /** Seeded development logins that must not exist (active) on a live server. */
    public const DEMO_STAFF = ['reception@commune.test', 'manager@commune.test', 'finance@commune.test', 'stateadmin@commune.test'];

    /** Cron commands and how stale their last run may be (hours). */
    public const CRON = ['bookings:tick' => 26, 'holds:cleanup' => 2, 'imports:cleanup' => 2];

    /** @var list<array{group: string, name: string, status: string, detail: string}> */
    private array $results = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /** @return list<array{group: string, name: string, status: string, detail: string}> */
    public function run(): array
    {
        $this->results = [];
        $production = App::config('app.env') === 'production';
        $this->php();
        $this->config($production);
        $this->files();
        $this->database($production);
        $this->mail($production);
        $this->cron();
        return $this->results;
    }

    public static function stamp(string $basePath, string $command): void
    {
        @file_put_contents($basePath . '/storage/cache/cron-' . preg_replace('/[^a-z0-9]+/', '-', $command) . '.stamp', date('c'));
    }

    private function add(string $group, string $name, string $status, string $detail = ''): void
    {
        $this->results[] = ['group' => $group, 'name' => $name, 'status' => $status, 'detail' => $detail];
    }

    private function php(): void
    {
        $this->add('PHP', 'Version ' . PHP_VERSION, version_compare(PHP_VERSION, '8.4.0', '>=') ? 'ok' : 'fail', 'PHP 8.4 or newer is required');
        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $e) => !extension_loaded($e)));
        $this->add('PHP', 'Extensions', $missing === [] ? 'ok' : 'fail', $missing === [] ? implode(', ', self::EXTENSIONS) : 'missing: ' . implode(', ', $missing));
        $opcache = extension_loaded('Zend OPcache');
        $this->add('PHP', 'OPcache', $opcache ? 'ok' : 'warn', $opcache ? 'loaded — in the FPM php.ini set opcache.enable=1 (+ validate_timestamps=0 with a reload on deploy)' : 'install/enable the opcache extension for production');
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $this->add('PHP', 'Upload limits', $upload >= 12 * 1024 * 1024 && $post >= 16 * 1024 * 1024 ? 'ok' : 'warn', sprintf('upload_max_filesize=%s post_max_size=%s (need ≥ 12M / 16M for layout photos)', ini_get('upload_max_filesize'), ini_get('post_max_size')));
        $memory = self::bytes((string) ini_get('memory_limit'));
        $this->add('PHP', 'memory_limit', $memory === -1 || $memory >= 256 * 1024 * 1024 ? 'ok' : 'warn', (string) ini_get('memory_limit') . ' (256M recommended for PDFs / XLSX)');
        $this->add('PHP', 'Time zone', date_default_timezone_get() === 'Asia/Kolkata' ? 'ok' : 'warn', date_default_timezone_get());
    }

    private function config(bool $production): void
    {
        $env = (string) App::config('app.env');
        $this->add('Config', 'APP_ENV', $env === 'production' ? 'ok' : 'warn', $env . ($env === 'production' ? '' : ' (set APP_ENV=production on the live server)'));
        $debug = (bool) App::config('app.debug');
        $this->add('Config', 'APP_DEBUG', $debug ? ($production ? 'fail' : 'warn') : 'ok', $debug ? 'true — stack traces are shown to visitors' : 'false');
        try {
            new AadhaarVault();
            $this->add('Config', 'APP_KEY', 'ok', '32-byte key set — back it up offline: without it stored Aadhaar numbers cannot be decrypted');
        } catch (\Throwable $e) {
            $this->add('Config', 'APP_KEY', 'fail', $e->getMessage());
        }
        $url = (string) App::config('app.url');
        $https = str_starts_with($url, 'https://');
        $this->add('Config', 'APP_URL', $https ? 'ok' : ($production ? 'fail' : 'warn'), $url . ($https ? '' : ' — emails link here; use https:// in production'));
        $secure = (bool) App::config('session.defaults.secure', false);
        $this->add('Config', 'Secure session cookie', $secure ? 'ok' : 'warn', $secure ? 'SESSION_SECURE_COOKIE=true' : 'set SESSION_SECURE_COOKIE=true behind HTTPS (also forced per request when HTTPS is detected)');
        $this->add('Config', 'Rate limits', (bool) App::config('security.rate_limits', true) ? 'ok' : 'fail', (bool) App::config('security.rate_limits', true) ? 'on' : 'RATE_LIMITS=false — never in production');
    }

    private function files(): void
    {
        $bad = [];
        foreach (self::WRITABLE as $dir) {
            $path = $this->basePath . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            if (!is_dir($path) || !is_writable($path)) {
                $bad[] = $dir;
            }
        }
        $this->add('Files', 'Writable directories', $bad === [] ? 'ok' : 'fail', $bad === [] ? implode(', ', self::WRITABLE) : 'not writable by ' . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'this user') . ': ' . implode(', ', $bad));
        $uploads = (string) App::config('app.uploads_path');
        $public = realpath($this->basePath . '/public') ?: $this->basePath . '/public';
        $this->add('Files', 'KYC uploads outside public/', str_starts_with((string) (realpath($uploads) ?: $uploads), $public) ? 'fail' : 'ok', $uploads);
        $env = $this->basePath . '/.env';
        $perms = is_file($env) ? fileperms($env) & 0777 : null;
        $this->add('Files', '.env permissions', $perms === null ? 'warn' : (($perms & 0007) === 0 ? 'ok' : 'warn'), $perms === null ? '.env not found (using real environment variables?)' : sprintf('%o%s', $perms, ($perms & 0007) === 0 ? '' : ' — make it unreadable by other users: chmod 640 .env'));
        $built = is_file($this->basePath . '/public/assets/css/app.css') && is_file($this->basePath . '/public/assets/vendor/alpine.min.js');
        $this->add('Files', 'Built assets', $built ? 'ok' : 'fail', $built ? 'public/assets present' : 'public/assets/css/app.css or vendor JS missing (they are committed — check the deploy)');
    }

    private function database(bool $production): void
    {
        try {
            $db = App::container()->get(Database::class);
            $version = (string) $db->scalar('SELECT VERSION()');
            $this->add('Database', 'Connection', 'ok', $version);
        } catch (\Throwable $e) {
            $this->add('Database', 'Connection', 'fail', $e->getMessage());
            return;
        }
        try {
            $status = (new Migrator($db, $this->basePath . '/' . App::config('database.migrations_path', 'database/migrations')))->status();
            $pending = array_values(array_filter($status, static fn (array $r) => !$r['ran']));
            $this->add('Database', 'Migrations', $pending === [] ? 'ok' : 'fail', $pending === [] ? count($status) . ' applied' : count($pending) . ' pending — run php bin/console migrate: ' . implode(', ', array_column($pending, 'migration')));
        } catch (\Throwable $e) {
            $this->add('Database', 'Migrations', 'fail', $e->getMessage());
            return;
        }
        try {
            $managers = (int) $db->scalar("SELECT COUNT(*) FROM staff_users WHERE role = 'centre_manager' AND is_active = 1");
            $this->add('Database', 'Centre Manager account', $managers > 0 ? 'ok' : 'fail', $managers > 0 ? $managers . ' active' : 'none — create one with php bin/console user:create');
            $in = implode(',', array_fill(0, count(self::DEMO_STAFF), '?'));
            $demo = $db->column("SELECT email FROM staff_users WHERE is_active = 1 AND email IN ({$in})", self::DEMO_STAFF);
            $this->add('Database', 'Demo staff logins', $demo === [] ? 'ok' : ($production ? 'fail' : 'warn'), $demo === [] ? 'none active' : 'active with the published password: ' . implode(', ', $demo) . ' — deactivate them in /staff/users');
            $row = $db->first('SELECT aadhaar_enc FROM customers WHERE aadhaar_enc IS NOT NULL LIMIT 1');
            if ($row !== null) {
                try {
                    (new AadhaarVault())->decrypt((string) $row['aadhaar_enc']);
                    $this->add('Database', 'APP_KEY decrypts stored Aadhaar', 'ok', 'sample row decrypted');
                } catch (\Throwable) {
                    $this->add('Database', 'APP_KEY decrypts stored Aadhaar', 'fail', 'APP_KEY does not match the key the data was encrypted with — restore the original key');
                }
            }
        } catch (\Throwable $e) {
            $this->add('Database', 'Accounts', 'warn', $e->getMessage());
        }
    }

    private function mail(bool $production): void
    {
        $dsn = (string) App::config('mail.dsn', '');
        $scheme = strtolower((string) (parse_url($dsn, PHP_URL_SCHEME) ?? ''));
        $real = in_array($scheme, ['smtp', 'smtps', 'sendmail', 'ses+smtp', 'sendgrid+smtp'], true);
        $this->add('Mail', 'MAIL_DSN', $real ? 'ok' : ($production ? 'fail' : 'warn'), $scheme . '://…' . ($real ? '' : ' — set a real SMTP DSN; log:// writes password links to storage/logs/mail.log'));
        $from = (string) App::config('mail.from.address', '');
        $this->add('Mail', 'From address', str_ends_with($from, '.test') ? 'warn' : 'ok', $from);
    }

    private function cron(): void
    {
        foreach (self::CRON as $command => $hours) {
            $file = $this->basePath . '/storage/cache/cron-' . preg_replace('/[^a-z0-9]+/', '-', $command) . '.stamp';
            $at = is_file($file) ? strtotime((string) file_get_contents($file)) : false;
            $fresh = $at !== false && time() - $at <= $hours * 3600;
            $this->add('Cron', $command, $fresh ? 'ok' : 'warn', $at === false ? 'never ran — add the crontab lines from docs/DEPLOYMENT.md' : 'last run ' . date('Y-m-d H:i', $at));
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
