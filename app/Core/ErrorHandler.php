<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Converts PHP errors to exceptions and exceptions to responses:
 *  - ValidationException -> redirect back with errors/old input (or 422 JSON)
 *  - HttpException       -> resources/views/errors/{status}.php
 *  - anything else       -> 500 page (debug: detailed trace page), logged.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?View $view = null,
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $this->debug ? '1' : '0');
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler(function (Throwable $e): void {
            $this->render($e, App::request())->send();
        });
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $this->render(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), App::request())->send();
            }
        });
    }

    public function render(Throwable $e, ?Request $request = null): Response
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidation($e, $request);
        }

        $status = $e instanceof HttpException ? $e->status() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];

        if ($status >= 500) {
            $this->logger?->error($e->getMessage(), ['exception' => $e, 'path' => $request?->path()]);
        }

        if ($request !== null && $request->wantsJson()) {
            $payload = ['message' => $status >= 500 && !$this->debug ? 'Server error' : ($e->getMessage() ?: 'Error')];
            if ($this->debug && $status >= 500) {
                $payload['exception'] = $e::class;
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }
            return Response::json($payload, $status, $headers);
        }

        if ($status >= 500 && $this->debug) {
            return Response::html($this->debugPage($e, $request), 500);
        }

        return Response::html($this->errorPage($status, $e), $status, $headers);
    }

    private function renderValidation(ValidationException $e, ?Request $request): Response
    {
        if ($request !== null && $request->wantsJson()) {
            return Response::json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
        $target = $e->redirectTo() ?? $request?->safeReferer() ?? ($request?->basePath() . $request?->path());
        return Response::redirect((string) $target)->withErrors($e->errors())->withInput($request?->post() ?? []);
    }

    private function errorPage(int $status, Throwable $e): string
    {
        $titles = [
            403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed',
            419 => 'Page expired', 429 => 'Too many requests', 500 => 'Something went wrong', 503 => 'Be right back',
        ];
        $data = [
            'status' => $status,
            'title' => $titles[$status] ?? 'Error',
            'message' => $e instanceof HttpException && $e->getMessage() !== '' ? $e->getMessage() : null,
        ];
        try {
            if ($this->view !== null) {
                $name = $this->view->exists("errors/{$status}") ? "errors/{$status}" : 'errors/error';
                return $this->view->render($name, $data);
            }
        } catch (Throwable $inner) {
            $this->logger?->error('Error page failed to render: ' . $inner->getMessage(), ['exception' => $inner]);
        }
        return '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><h1>' . $status . ' ' . e($data['title']) . '</h1>';
    }

    private function debugPage(Throwable $e, ?Request $request): string
    {
        $frames = '';
        foreach ($e->getTrace() as $i => $frame) {
            $where = isset($frame['file']) ? e($this->shortPath($frame['file'])) . ':' . ($frame['line'] ?? '?') : '[internal]';
            $call = e(($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']) . '()';
            $frames .= "<li><span class=\"n\">#{$i}</span> <code>{$call}</code><br><small>{$where}</small></li>";
        }
        $snippet = $this->snippet($e->getFile(), $e->getLine());
        $class = e($e::class);
        $message = e($e->getMessage());
        $location = e($this->shortPath($e->getFile())) . ':' . $e->getLine();
        $method = e($request?->method() ?? 'CLI');
        $path = e($request?->path() ?? '');
        $previous = $e->getPrevious() !== null ? '<p class="prev">Caused by <b>' . e($e->getPrevious()::class) . '</b>: ' . e($e->getPrevious()->getMessage()) . '</p>' : '';

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$class}</title><style>
body{margin:0;font:14px/1.5 ui-sans-serif,system-ui,sans-serif;background:#0b1020;color:#e5e7eb}
header{padding:32px 40px;background:linear-gradient(135deg,#7f1d1d,#1e1b4b)}
h1{margin:0 0 8px;font-size:22px}.cls{color:#fca5a5;font-weight:600;letter-spacing:.02em}
.meta{opacity:.75}.wrap{padding:24px 40px;display:grid;gap:24px}
pre{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:16px;overflow:auto;margin:0}
.hl{background:#7f1d1d;display:block}ol{list-style:none;padding:0;margin:0;background:#111827;border-radius:12px;border:1px solid #1f2937}
li{padding:10px 16px;border-bottom:1px solid #1f2937}code{color:#93c5fd}small{color:#9ca3af}.n{color:#6b7280;display:inline-block;width:32px}.prev{color:#fcd34d}
</style></head><body><header><div class="cls">{$class}</div><h1>{$message}</h1>
<div class="meta">{$location} &middot; {$method} {$path}</div>{$previous}</header>
<div class="wrap"><pre>{$snippet}</pre><ol>{$frames}</ol></div></body></html>
HTML;
    }

    private function snippet(string $file, int $line): string
    {
        if (!is_readable($file)) {
            return '';
        }
        $lines = file($file) ?: [];
        $start = max(0, $line - 8);
        $out = '';
        foreach (array_slice($lines, $start, 15, true) as $i => $text) {
            $num = str_pad((string) ($i + 1), 4, ' ', STR_PAD_LEFT);
            $row = $num . '  ' . e(rtrim($text, "\n"));
            $out .= $i + 1 === $line ? "<span class=\"hl\">{$row}</span>" : $row . "\n";
        }
        return $out;
    }

    private function shortPath(string $file): string
    {
        return str_replace(App::basePath() . '/', '', $file);
    }
}
