<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Middleware contract. Parameters after the colon in a route definition are
 * passed as extra string arguments: 'role:centre_manager,state_admin'.
 */
interface Middleware
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string ...$params): Response;
}
