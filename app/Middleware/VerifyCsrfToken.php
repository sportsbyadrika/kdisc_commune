<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\TokenMismatchException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Global middleware: rejects state-changing requests without a valid CSRF token (HTTP 419). */
final class VerifyCsrfToken implements Middleware
{
    /**
     * Paths (with * wildcard) exempt from CSRF, e.g. payment gateway webhooks: '/webhooks/*'.
     *
     * @var list<string>
     */
    private array $except = [];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $this->isExempt($request)) {
            return $next($request);
        }
        $token = $request->input('_token');
        $token = is_string($token) ? $token : $request->header('X-CSRF-TOKEN');
        if (!$this->csrf->validate($token)) {
            throw new TokenMismatchException();
        }
        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }
        return false;
    }
}
