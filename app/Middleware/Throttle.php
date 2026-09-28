<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Security\RateLimiter;
use Closure;

/**
 * Route rate limiting: ->middleware('throttle:quote,60,1') = at most 60 requests per minute per client for the
 * "quote" limiter. The client is the IP plus the signed-in staff / visitor id (so one office NAT does not lock out a
 * whole team once people sign in). Over the limit → 429 with Retry-After (JSON for XHR). Limits are skipped when
 * APP_ENV=testing unless the test enables them (RateLimiter is exercised directly by the tests).
 */
final class Throttle implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $name = $params[0] ?? 'global';
        $max = max(1, (int) ($params[1] ?? 60));
        $minutes = max(1, (int) ($params[2] ?? 1));
        if (!(bool) App::config('security.rate_limits', true)) {
            return $next($request);
        }
        $wait = $this->limiter->attempt($name, self::clientKey($request), $max, $minutes * 60);
        if ($wait > 0) {
            throw new HttpException(429, sprintf('Too many requests. Please wait %s and try again.', $wait >= 90 ? (int) ceil($wait / 60) . ' minutes' : $wait . ' seconds'), ['Retry-After' => (string) $wait]);
        }
        return $next($request);
    }

    public static function clientKey(Request $request): string
    {
        $context = (string) ($request->attribute('session_context') ?? 'site');
        $user = null;
        try {
            $user = $context === 'staff' ? App::guard('staff')->id() : App::guard('visitor')->id();
        } catch (\Throwable) {
        }
        return $request->ip() . '|' . $context . ':' . ($user ?? 'guest');
    }
}
