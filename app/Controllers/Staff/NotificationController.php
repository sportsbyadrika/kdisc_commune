<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Notify\StaffInbox;

/** Staff notification inbox (/staff/notifications) + mark read; the header bell dropdown links here. */
final class NotificationController extends StaffController
{
    public function __construct(private readonly StaffInbox $inbox)
    {
    }

    public function index(Request $request): Response
    {
        $unread = $request->string('show') === 'unread';
        return $this->view('staff/notifications/index', [
            'title' => 'Notifications',
            'subtitle' => 'Booking changes, Finance queries and reminders for your role.',
            'unreadOnly' => $unread,
            'result' => $this->inbox->page($this->staffId(), $unread, max(1, $request->int('page', 1))),
            'unread' => $this->inbox->unreadCount($this->staffId()),
        ]);
    }

    /** Mark one read (and follow its link when it has one). */
    public function open(int $id): Response
    {
        $n = $this->inbox->find($id, $this->staffId()) ?? throw new NotFoundException();
        $this->inbox->markRead($id, $this->staffId());
        return redirect($n['url'] ?? url('staff.notifications.index'));
    }

    public function read(Request $request, int $id): Response
    {
        $this->inbox->find($id, $this->staffId()) ?? throw new NotFoundException();
        $this->inbox->markRead($id, $this->staffId());
        return $request->wantsJson() ? Response::json(['unread' => $this->inbox->unreadCount($this->staffId())]) : back(url('staff.notifications.index'));
    }

    public function readAll(Request $request): Response
    {
        $n = $this->inbox->markAllRead($this->staffId());
        return $request->wantsJson() ? Response::json(['unread' => 0]) : back(url('staff.notifications.index'))->with('success', $n . ' notification' . ($n === 1 ? '' : 's') . ' marked as read.');
    }
}
