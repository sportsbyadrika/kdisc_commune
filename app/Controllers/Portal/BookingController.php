<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Exceptions\NotFoundException;
use App\Core\Response;
use App\Models\Customer;
use App\Services\Bookings\BookingDirectory;

/** /my/bookings — the visitor's own booking requests and bookings, with the status timeline. */
final class BookingController extends PortalController
{
    public function __construct(private readonly BookingDirectory $bookings)
    {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        return $this->view('portal/bookings/index', [
            'title' => 'My bookings',
            'customer' => Customer::safe($customer),
            'bookings' => $this->bookings->forCustomer((int) $customer['id']),
        ]);
    }

    public function show(string $no): Response
    {
        $customer = $this->customer();
        $booking = $this->bookings->findByNo($no, (int) $customer['id']) ?? throw new NotFoundException();
        return $this->view('portal/bookings/show', [
            'title' => 'Booking ' . $booking['booking_no'],
            'customer' => Customer::safe($customer),
            'booking' => $booking,
            'seats' => $this->bookings->seats((int) $booking['id']),
            'facilities' => $this->bookings->facilities((int) $booking['id']),
            'timeline' => BookingDirectory::timeline($booking),
        ]);
    }
}
