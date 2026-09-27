<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Enums\StaffRole;
use Closure;

/**
 * Restrict a staff route to an ability from StaffRole::abilities():
 * ->middleware('can:layout.design'). Several abilities = any of them.
 */
final class Can implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = App::guard('staff')->user();
        $role = StaffRole::tryFrom((string) ($user['role'] ?? ''));
        foreach ($abilities as $ability) {
            if ($role?->can($ability)) {
                return $next($request);
            }
        }
        throw new ForbiddenException();
    }
}
