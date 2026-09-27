<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Closure;

/** Requires a signed-in visitor account (portal). Redirects to /login. */
final class VisitorAuth implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $guard = App::guard('visitor');
        if ($guard->check()) {
            $request->withAttribute('account', $guard->user());
            return $next($request);
        }
        if ($request->wantsJson()) {
            return Response::json(['message' => 'Unauthenticated.'], 401);
        }
        if ($request->isMethod('GET')) {
            Session::current()?->put('_intended', $request->fullPath());
        }
        return redirect(url('portal.login'))->with('warning', 'Please sign in to continue.');
    }
}
