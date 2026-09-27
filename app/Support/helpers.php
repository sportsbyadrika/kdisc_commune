<?php

declare(strict_types=1);

/*
 * Global helper functions (autoloaded via composer "files").
 * Keep this list small and stable — later modules rely on these names.
 */

use App\Core\App;
use App\Core\Auth\Guard;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\RedirectResponse;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Services\SettingsService;
use Psr\Log\LoggerInterface;

if (!function_exists('e')) {
    /** Escape for HTML text and attribute context. ALWAYS use for untrusted output: <?= e($name) ?> */
    function e(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return App::config($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return App::basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return App::basePath('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return App::basePath('public' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('url')) {
    /**
     * URL for a named route: url('staff.dashboard'), url('visitors.show', ['id' => 5]).
     * A value starting with "/" is treated as a literal path: url('/pricing').
     *
     * @param array<string, scalar> $params
     */
    function url(string $nameOrPath, array $params = []): string
    {
        $base = App::request()?->basePath() ?? '';
        if (str_starts_with($nameOrPath, '/')) {
            $query = http_build_query($params);
            return $base . $nameOrPath . ($query !== '' ? '?' . $query : '');
        }
        /** @var Router $router */
        $router = App::container()->get(Router::class);
        return $base . $router->url($nameOrPath, $params);
    }
}

if (!function_exists('route_is')) {
    /** True when the current route name matches (supports trailing wildcard: 'staff.visitors.*'). */
    function route_is(string ...$patterns): bool
    {
        $route = App::request()?->attribute('route');
        $name = $route instanceof \App\Core\Route ? (string) $route->getName() : '';
        foreach ($patterns as $pattern) {
            if ($name === $pattern || (str_ends_with($pattern, '*') && str_starts_with($name, rtrim($pattern, '*')))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('route_exists')) {
    function route_exists(string $name): bool
    {
        /** @var Router $router */
        $router = App::container()->get(Router::class);
        return $router->has($name);
    }
}

if (!function_exists('absolute_url')) {
    /**
     * Fully-qualified URL (for emails / PDFs).
     * @param array<string, scalar> $params
     */
    function absolute_url(string $nameOrPath, array $params = []): string
    {
        return rtrim((string) config('app.url', ''), '/') . url($nameOrPath, $params);
    }
}

if (!function_exists('asset')) {
    /** Public asset URL with cache-busting version: asset('assets/css/app.css') */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = public_path($path);
        $version = is_file($file) ? '?v=' . substr(md5((string) filemtime($file)), 0, 8) : '';
        return (App::request()?->basePath() ?? '') . '/' . $path . $version;
    }
}

if (!function_exists('media')) {
    /** URL for a stored media path (DB value like "media/building.svg"). */
    function media(?string $path, string $fallback = 'media/placeholder.svg'): string
    {
        $path = $path !== null && $path !== '' ? $path : $fallback;
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        return asset($path);
    }
}

if (!function_exists('view')) {
    /** @param array<string, mixed> $data */
    function view(string $name, array $data = [], int $status = 200): Response
    {
        /** @var View $view */
        $view = App::container()->get(View::class);
        return Response::html($view->render($name, $data), $status);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): RedirectResponse
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('back')) {
    function back(string $fallback = '/'): RedirectResponse
    {
        $request = App::request();
        $ref = $request?->referer();
        return Response::redirect($ref ?? url($fallback));
    }
}

if (!function_exists('session')) {
    function session(): Session
    {
        return Session::current() ?? throw new RuntimeException('No active session.');
    }
}

if (!function_exists('flash')) {
    /** Flash a message for the next request: flash('success', 'Saved'). */
    function flash(string $key, mixed $value): void
    {
        Session::current()?->flash($key, $value);
    }
}

if (!function_exists('old')) {
    /** Previous input after a failed validation: value="<?= e(old('email')) ?>" */
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::current()?->old($key, $default) ?? $default;
    }
}

if (!function_exists('errors')) {
    /**
     * Validation errors from the previous request.
     *   errors()            -> all [field => [messages]]
     *   errors('email')     -> first message for field or null
     *
     * @return array<string, list<string>>|string|null
     */
    function errors(?string $field = null): array|string|null
    {
        $errors = Session::current()?->errors() ?? [];
        if ($field === null) {
            return $errors;
        }
        return $errors[$field][0] ?? null;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        /** @var Csrf $csrf */
        $csrf = App::container()->get(Csrf::class);
        return $csrf->token();
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden CSRF input. REQUIRED in every <form method="post">. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('auth')) {
    /** auth('staff')->user() / auth('visitor')->check() */
    function auth(string $guard = 'staff'): Guard
    {
        return App::guard($guard);
    }
}

if (!function_exists('staff')) {
    /**
     * Currently signed-in staff user row, or null.
     * @return array<string, mixed>|null
     */
    function staff(): ?array
    {
        return App::guard('staff')->user();
    }
}

if (!function_exists('db')) {
    function db(): Database
    {
        /** @var Database */
        return App::container()->get(Database::class);
    }
}

if (!function_exists('logger')) {
    function logger(): LoggerInterface
    {
        /** @var LoggerInterface */
        return App::container()->get(LoggerInterface::class);
    }
}

if (!function_exists('setting')) {
    /** Value from the `settings` table (cached per request): setting('gst_rate', 18) */
    function setting(string $key, mixed $default = null): mixed
    {
        /** @var SettingsService $settings */
        $settings = App::container()->get(SettingsService::class);
        return $settings->get($key, $default);
    }
}

if (!function_exists('icon')) {
    /**
     * Inline a Lucide SVG icon from resources/icons (add icons via bin/vendor-js.mjs).
     *   <?= icon('armchair', 'size-5 text-brand-600') ?>
     */
    function icon(string $name, string $class = 'size-5', string $label = ''): string
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $file = App::basePath('resources/icons/' . basename($name) . '.svg');
            $cache[$name] = is_file($file) ? trim((string) file_get_contents($file)) : '';
        }
        if ($cache[$name] === '') {
            return '';
        }
        $aria = $label !== '' ? 'role="img" aria-label="' . e($label) . '"' : 'aria-hidden="true"';
        return (string) preg_replace('/^<svg /', '<svg class="' . e($class) . '" ' . $aria . ' ', $cache[$name], 1);
    }
}

if (!function_exists('money')) {
    /** Indian-format rupee amount: money(125000) -> "₹1,25,000"; money(99.5, 2) -> "₹99.50" */
    function money(int|float|string|null $amount, int $decimals = 0): string
    {
        $amount = (float) ($amount ?? 0);
        $negative = $amount < 0;
        $fixed = number_format(abs($amount), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $fixed), 2, '');
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        if ($rest !== '') {
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        return ($negative ? '-' : '') . '₹' . $int . ($decimals > 0 ? '.' . $frac : '');
    }
}

if (!function_exists('format_date')) {
    function format_date(DateTimeInterface|string|null $date, string $format = 'd M Y'): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $d = $date instanceof DateTimeInterface ? $date : new DateTimeImmutable($date);
        return $d->format($format);
    }
}

if (!function_exists('class_names')) {
    /**
     * Build a class attribute from conditional parts:
     *   class_names('btn', ['btn-active' => $active, 'opacity-50' => $disabled])
     *
     * @param string|array<int|string, mixed> ...$parts
     */
    function class_names(string|array ...$parts): string
    {
        $out = [];
        foreach ($parts as $part) {
            if (is_string($part)) {
                $out[] = $part;
                continue;
            }
            foreach ($part as $key => $value) {
                if (is_int($key)) {
                    $out[] = (string) $value;
                } elseif ($value) {
                    $out[] = $key;
                }
            }
        }
        return trim(implode(' ', array_filter($out)));
    }
}

if (!function_exists('attrs')) {
    /**
     * Render extra HTML attributes safely: attrs(['data-id' => 5, 'disabled' => true])
     *
     * @param array<string, scalar|null> $attributes
     */
    function attrs(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $html .= $value === true ? ' ' . e($name) : ' ' . e($name) . '="' . e($value) . '"';
        }
        return $html;
    }
}
