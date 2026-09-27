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
 * Restrict a staff route to roles: ->middleware('role:centre_manager,finance_admin').
 * Must run after auth.staff.
 */
final class Role implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = App::guard('staff')->user();
        $role = StaffRole::tryFrom((string) ($user['role'] ?? ''));
        if ($role === null || !in_array($role->value, $roles, true)) {
            throw new ForbiddenException();
        }
        return $next($request);
    }
}
