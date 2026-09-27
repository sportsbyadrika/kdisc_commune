<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Bookings\BookingDirectory;

/**
 * Read-only booking list + detail (batch 3). Batch 4 adds approve / reject, payments and check-in here.
 */
final class BookingController extends Controller
{
    public function __construct(private readonly BookingDirectory $bookings)
    {
    }

    public function index(Request $request): Response
    {
        $filters = ['q' => $request->string('q'), 'status' => $request->string('status')];
        return $this->view('staff/bookings/index', [
            'title' => 'Bookings',
            'filters' => $filters,
            'bookings' => $this->bookings->search($filters),
            'counts' => $this->bookings->statusCounts(),
        ]);
    }

    public function show(string $no): Response
    {
        $booking = $this->bookings->findByNo($no) ?? throw new NotFoundException();
        return $this->view('staff/bookings/show', [
            'title' => 'Booking ' . $booking['booking_no'],
            'booking' => $booking,
            'seats' => $this->bookings->seats((int) $booking['id']),
            'facilities' => $this->bookings->facilities((int) $booking['id']),
            'timeline' => BookingDirectory::timeline($booking),
        ]);
    }
}
