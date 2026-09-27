<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Notify\StaffInbox;
use Closure;

/**
 * Requires a signed-in, active staff user. Redirects to /staff/login (remembering the intended URL). Shares the
 * notification bell data (`staffInbox`) with the staff views.
 */
final class StaffAuth implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $guard = App::guard('staff');
        if ($guard->check()) {
            $request->withAttribute('staff', $guard->user());
            if ($request->isMethod('GET') && !$request->wantsJson()) {
                // header bell: unread count + latest notifications for every staff page
                $inbox = App::container()->get(StaffInbox::class);
                $id = (int) ($guard->user()['id'] ?? 0);
                App::container()->get(View::class)->share('staffInbox', ['unread' => $inbox->unreadCount($id), 'latest' => $inbox->latest($id, 6)]);
            }
            return $next($request);
        }
        if ($request->wantsJson()) {
            return Response::json(['message' => 'Unauthenticated.'], 401);
        }
        if ($request->isMethod('GET')) {
            Session::current()?->put('_intended', $request->fullPath());
        }
        return redirect(url('staff.login'))->with('warning', 'Please sign in to continue.');
    }
}
