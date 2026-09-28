<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Controllers\Staff\Finance\FinanceDocumentController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Response;
use App\Models\Customer;
use App\Services\Bookings\BookingDirectory;
use App\Services\Pdf\BookingDocuments;

/** Staff downloads of the booking allotment letter and the visitor ID card (generated on demand). */
final class BookingDocumentController extends Controller
{
    public function __construct(private readonly BookingDocuments $documents, private readonly BookingDirectory $bookings)
    {
    }

    public function allotment(string $no): Response
    {
        $booking = $this->bookings->findByNo($no) ?? throw new NotFoundException('Booking not found.');
        if (!BookingDocuments::allotmentAvailable($booking)) {
            return redirect(url('staff.bookings.show', ['no' => $no]))->with('warning', 'The allotment letter is available once the booking is confirmed.');
        }
        return FinanceDocumentController::inline($this->documents->allotmentLetter($booking), 'Allotment-' . $no . '.pdf');
    }

    public function idCard(string $ref): Response
    {
        $customer = Customer::findByRef($ref) ?? throw new NotFoundException('Visitor not found.');
        if (empty($customer['unique_id'])) {
            return redirect(url('staff.visitors.show', ['ref' => $ref]))->with('warning', 'The ID card is available once the Unique Visitor ID is issued.');
        }
        return FinanceDocumentController::inline($this->documents->idCard($customer), 'Visitor-ID-' . $customer['unique_id'] . '.pdf');
    }
}
