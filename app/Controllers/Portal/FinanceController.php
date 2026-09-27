<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Staff\Finance\FinanceDocumentController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Response;
use App\Models\Customer;
use App\Services\Bookings\BookingDirectory;
use App\Services\Finance\FinanceDocuments;
use App\Services\Pdf\BookingDocuments;

/**
 * Visitor portal: invoices, receipts, credit notes and deposit refunds (/my/invoices) with PDF downloads, the
 * allotment letter of a confirmed booking and the visitor ID card. Everything is scoped to the signed-in visitor
 * (other visitors' ids → 404).
 */
final class FinanceController extends PortalController
{
    public function __construct(private readonly FinanceDocuments $docs, private readonly BookingDocuments $documents, private readonly BookingDirectory $bookings)
    {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        $lists = $this->docs->forCustomer((int) $customer['id']);
        return $this->view('portal/invoices', [
            'title' => 'Invoices & receipts',
            'customer' => Customer::safe($customer),
            'docs' => $lists,
            'letters' => array_values(array_filter($this->bookings->forCustomer((int) $customer['id']), static fn (array $b) => BookingDocuments::allotmentAvailable($b))),
        ]);
    }

    public function pdf(string $type, int $id, string $slug): Response
    {
        $t = FinanceDocuments::fromUrlType($type);
        $row = $this->docs->find($t, $id, (int) $this->customer()['id']) ?? throw new NotFoundException();
        if (!hash_equals(FinanceDocuments::slug($t, $row), $slug)) {
            throw new NotFoundException();
        }
        $doc = $this->docs->pdf($t, $row);
        return FinanceDocumentController::inline($doc['bytes'], $doc['filename']);
    }

    public function allotment(string $no): Response
    {
        $booking = $this->bookings->findByNo($no, (int) $this->customer()['id']) ?? throw new NotFoundException();
        if (!BookingDocuments::allotmentAvailable($booking)) {
            throw new NotFoundException('The allotment letter is available once the booking is confirmed.');
        }
        return FinanceDocumentController::inline($this->documents->allotmentLetter($booking), 'Allotment-' . $no . '.pdf');
    }

    public function idCard(): Response
    {
        $customer = $this->customer();
        if (empty($customer['unique_id'])) {
            throw new NotFoundException('Your ID card is available once your profile is submitted.');
        }
        return FinanceDocumentController::inline($this->documents->idCard($customer), 'Visitor-ID-' . $customer['unique_id'] . '.pdf');
    }
}
