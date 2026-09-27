<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Services\Bookings\Actor;
use App\Services\Bookings\CheckinService;
use App\Services\Bookings\WorkflowException;
use App\Services\Visitors\VisitorDirectory;

/**
 * /staff/checkin — QR / Unique ID check-in desk (spec 6.2): type or scan the visitor's Unique ID (the QR on their
 * ID card holds it; the page uses the BarcodeDetector API when the browser has it) → today's bookings with
 * per-seat check-in / check-out. Also the JSON endpoint behind the explorer seat popover.
 */
final class CheckinController extends Controller
{
    public function __construct(private readonly CheckinService $checkins)
    {
    }

    public function index(Request $request, VisitorDirectory $directory): Response
    {
        $q = trim($request->string('id'));
        $customer = null;
        $matches = [];
        if ($q !== '') {
            $customer = Customer::findByUniqueId(strtoupper($q));
            if ($customer === null) {
                $matches = $directory->search(['q' => $q], 1, 6)['rows'];
                if (count($matches) === 1) {
                    $customer = Customer::find((int) $matches[0]['id']);
                    $matches = [];
                }
            }
        }
        return $this->view('staff/checkin/index', [
            'title' => 'Check-in desk',
            'q' => $q,
            'customer' => $customer !== null ? Customer::safe($customer) : null,
            'bookings' => $customer !== null ? $this->checkins->forVisitor((int) $customer['id']) : [],
            'matches' => $matches,
            'checkedIn' => $this->checkins->checkedInCount(),
        ]);
    }

    /** POST /staff/checkin/seat {seat_id, action: in|out} — explorer popover (JSON). */
    public function seat(Request $request): Response
    {
        try {
            $r = $this->checkins->toggleBySeat($request->int('seat_id'), $request->string('action') === 'out' ? 'out' : 'in', Actor::staff((array) staff()));
        } catch (WorkflowException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }
        $verb = $r['action'] === 'out' ? 'Checked out' : 'Checked in';
        return Response::json($r + ['message' => sprintf('%s %s · %s%s', $verb, implode(', ', $r['seats']), $r['booking_no'], !empty($r['activated']) ? ' — booking now active' : (!empty($r['completed']) ? ' — booking completed' : ''))]);
    }
}
