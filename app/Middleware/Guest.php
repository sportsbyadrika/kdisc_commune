<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use Closure;

/** Only for signed-out users (login/register pages). Usage: guest:staff | guest:visitor */
final class Guest implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $guardName = $params[0] ?? 'staff';
        if (App::guard($guardName)->check()) {
            $home = (string) App::config("auth.guards.{$guardName}.home_route", 'home');
            return redirect(url($home));
        }
        return $next($request);
    }
}
