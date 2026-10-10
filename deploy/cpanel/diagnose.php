<?php
/**
 * Commune self-check for shared hosting — published by deploy/cpanel/deploy.sh as <web root>/diagnose.php.
 *
 * Open https://<your-domain>/diagnose.php within 30 minutes of a deploy: every check gets a green ✓ or a red ✗
 * with the reason. After 30 minutes it answers 404 (the deploy rewrites the file, which restarts the clock).
 * It never prints passwords, keys or other .env values.
 */
declare(strict_types=1);

const APP_PATH = '__APP_PATH__';      // replaced by deploy.sh
const OPEN_MINUTES = 30;

if (time() - (int) filemtime(__FILE__) > OPEN_MINUTES * 60) {
    http_response_code(404);
    exit('Not found');
}
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex');

$rows = [];
$add = static function (string $check, bool $ok, string $detail = '') use (&$rows): bool {
    $rows[] = [$check, $ok, $detail];
    return $ok;
};
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

// 1. PHP
$add('PHP version ' . PHP_VERSION . ' (' . PHP_SAPI . ')', PHP_VERSION_ID >= 80200, PHP_VERSION_ID >= 80200 ? '' : 'needs 8.2 or newer: cPanel → Select PHP Version / MultiPHP Manager');
$need = ['pdo_mysql', 'sodium', 'mbstring', 'intl', 'gd', 'zip', 'fileinfo', 'dom', 'xml', 'xmlreader', 'xmlwriter', 'simplexml', 'zlib', 'iconv', 'ctype', 'openssl', 'json'];
$missing = array_values(array_filter($need, static fn (string $e): bool => !extension_loaded($e)));
$add('PHP extensions', $missing === [], $missing === [] ? implode(', ', $need) : 'missing: ' . implode(', ', $missing) . ' — tick them in cPanel → Select PHP Version → Extensions (pdo_mysql may be called nd_pdo_mysql)');
if (extension_loaded('psr')) {
    $add('"psr" PHP extension', true, 'loaded — fine (the app does not depend on Monolog)');
}
$add('memory_limit', true, (string) ini_get('memory_limit') . ' (256M recommended for PDFs/Excel)');
$ob = (string) ini_get('open_basedir');
$add('open_basedir', $ob === '' || str_contains($ob, dirname(APP_PATH)) || str_contains($ob, APP_PATH), $ob === '' ? 'not set' : $ob);

// 2. Files
$add('App folder ' . APP_PATH, is_dir(APP_PATH) && is_readable(APP_PATH . '/public/index.php'), is_dir(APP_PATH) ? '' : 'missing — run Deploy HEAD Commit');
$add('Libraries (vendor/autoload.php)', is_file(APP_PATH . '/vendor/autoload.php'), 'copied from deploy/vendor on every deploy');
$env = APP_PATH . '/.env';
$envText = is_readable($env) ? (string) file_get_contents($env) : '';
$add('.env readable', $envText !== '', $envText !== '' ? '' : 'missing/unreadable: ' . $env);
foreach (['storage/logs', 'storage/sessions', 'storage/cache', 'storage/uploads', 'storage/pdf', 'storage/exports', 'storage/imports', 'public/media/uploads'] as $dir) {
    $p = APP_PATH . '/' . $dir;
    if (!(is_dir($p) && is_writable($p))) {
        $add('Writable ' . $dir, false, 'not writable by ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()));
    }
}
$add('Writable storage folders', !array_filter($rows, static fn ($r) => str_starts_with($r[0], 'Writable ') && !$r[1]));
$bootLog = APP_PATH . '/storage/logs/boot-error.log';
if (is_readable($bootLog)) {
    $lines = array_slice(file($bootLog, FILE_IGNORE_NEW_LINES) ?: [], -3);
    $add('Last start-up errors (boot-error.log)', false, implode("\n", $lines));
}

// 3. App start-up + database
$booted = false;
if (is_file(APP_PATH . '/vendor/autoload.php') && $envText !== '') {
    try {
        require APP_PATH . '/vendor/autoload.php';
        App\Core\App::boot(APP_PATH);
        $booted = $add('App starts (config, .env, services)', true);
        $add('APP_ENV / APP_DEBUG', true, (string) App\Core\App::config('app.env') . ' / ' . (App\Core\App::config('app.debug') ? 'true — set false when done' : 'false'));
        $add('APP_KEY set', str_starts_with((string) App\Core\App::config('app.key'), 'base64:'), 'used to encrypt Aadhaar numbers');
        $add('APP_URL', true, (string) App\Core\App::config('app.url'));
    } catch (Throwable $e) {
        $add('App starts (config, .env, services)', false, $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            . (str_contains($e->getMessage(), 'Dotenv') || $e instanceof Dotenv\Exception\InvalidFileException ? ' — wrap values with # $ spaces or quotes in single quotes in .env' : ''));
    }
}
if ($booted) {
    try {
        $db = App\Core\Database::getInstance();
        $db->scalar('SELECT 1');   // connects (lazily) — a wrong password fails here
        $add('Database connection', true, (string) App\Core\App::config('database.connections.mysql.database', '') ?: 'connected');
        try {
            $tables = (int) $db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
            $migrations = (int) $db->scalar('SELECT COUNT(*) FROM migrations');
            $seats = (int) $db->scalar('SELECT COUNT(*) FROM seats');
            $staff = (int) $db->scalar('SELECT COUNT(*) FROM staff_users WHERE is_active = 1');
            $add('Tables', $tables >= 30, "{$tables} tables, {$migrations} migrations recorded");
            $add('Reference data', $seats > 0, "{$seats} seats" . ($seats > 0 ? '' : ' — import deploy/sql/install.sql or deploy again'));
            $add('Staff logins', $staff > 0, $staff > 0 ? "{$staff} active" : 'none yet — create the first Centre Manager (bin/console user:create)');
        } catch (Throwable $e) {
            $add('Tables', false, $e->getMessage() . ' — import deploy/sql/install.sql in phpMyAdmin, or deploy again');
        }
    } catch (Throwable $e) {
        $add('Database connection', false, $e->getMessage() . ' — check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env (cPanel names are prefixed: shooting_…)');
    }
    try {
        $request = App\Core\Request::create('GET', '/');
        $response = App\Core\App::instance()->handle($request);
        $add('Home page renders', $response->status() < 500, 'HTTP ' . $response->status());
    } catch (Throwable $e) {
        $add('Home page renders', false, $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

$bad = count(array_filter($rows, static fn ($r) => !$r[1]));
?><!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Commune self-check</title>
<style>
  body{font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f6f7f9;color:#111827}
  main{max-width:880px;margin:32px auto;padding:0 16px}
  h1{font-size:22px;margin:0 0 4px} p{color:#4b5563;margin:0 0 16px}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.06)}
  td{padding:10px 12px;border-top:1px solid #eef0f3;vertical-align:top} td:first-child{width:28px;font-weight:700}
  .ok{color:#15803d}.bad{color:#b91c1c} pre{margin:0;white-space:pre-wrap;font:13px/1.45 ui-monospace,Menlo,monospace;color:#374151}
  .sum{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:600;margin-bottom:12px}
</style>
<main>
  <h1>Commune self-check</h1>
  <p>Available for <?= OPEN_MINUTES ?> minutes after each deploy. No passwords or keys are shown.</p>
  <span class="sum <?= $bad ? 'bad' : 'ok' ?>" style="background:<?= $bad ? '#fee2e2' : '#dcfce7' ?>"><?= $bad ? $bad . ' problem(s) found' : 'All checks passed' ?></span>
  <table>
  <?php foreach ($rows as [$check, $ok, $detail]): ?>
    <tr><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?></td><td><strong><?= $h($check) ?></strong><?php if ($detail !== ''): ?><pre><?= $h($detail) ?></pre><?php endif ?></td></tr>
  <?php endforeach ?>
  </table>
</main>
