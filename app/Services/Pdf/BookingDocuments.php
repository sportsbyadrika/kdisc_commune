<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\PaymentRule;
use App\Models\Customer;
use App\Services\Bookings\BookingDirectory;
use App\Services\Finance\FinanceSettings;
use App\Services\Kyc\DocumentStore;
use App\Services\Payments\PaymentLedger;
use App\Services\Space\BookingPeriod;
use App\Services\Space\MiniMapPresenter;
use App\Services\Visitors\QrCodeRenderer;
use App\Support\Clock;

/**
 * Non-finance PDFs (spec 9), generated on demand (not stored — they reflect the booking as it is now):
 *
 *   allotmentLetter()  booking allotment letter for confirmed / active / completed bookings — booking details,
 *                      the allotted seats (current rows, after any handover), payment terms, house rules and a
 *                      static seat-map snapshot per floor with the allotted seats highlighted (SeatMapImage).
 *   idCard()           CR80 visitor ID card (85.6 × 54 mm) — photo if uploaded, Unique ID, QR of the Unique ID.
 */
final class BookingDocuments
{
    /** CR80 card in PDF points (85.6 × 54 mm). */
    public const CR80 = [0.0, 0.0, 242.65, 153.07];

    public function __construct(
        private readonly Database $db,
        private readonly PdfService $pdf,
        private readonly BookingDirectory $bookings,
        private readonly MiniMapPresenter $maps,
        private readonly PaymentLedger $ledger,
        private readonly FinanceSettings $settings,
        private readonly QrCodeRenderer $qr,
        private readonly DocumentStore $files,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $booking BookingDirectory row */
    public static function allotmentAvailable(array $booking): bool
    {
        return in_array((string) $booking['status'], [BookingStatus::Confirmed->value, BookingStatus::Active->value, BookingStatus::Completed->value], true);
    }

    /** @param array<string, mixed> $booking BookingDirectory::findByNo() row */
    public function allotmentLetter(array $booking): string
    {
        $seats = array_values(array_filter($this->bookings->seats((int) $booking['id']), static fn (array $s) => $s['transferred_to_id'] === null));
        $customer = (array) $this->db->first('SELECT * FROM customers WHERE id = ?', [(int) $booking['customer_id']]);
        $period = $booking['start_time'] !== null
            ? BookingPeriod::hours((string) $booking['start_date'], substr((string) $booking['start_time'], 0, 5), substr((string) $booking['end_time'], 0, 5))
            : BookingPeriod::days((string) $booking['start_date'], (string) $booking['start_date']);
        $maps = [];
        $byFloor = [];
        foreach ($seats as $s) {
            $byFloor[(int) $s['floor_id']][] = (int) $s['seat_key'];
        }
        foreach ($byFloor as $floorId => $keys) {
            $floor = $this->db->first('SELECT * FROM floors WHERE id = ?', [$floorId]);
            if ($floor === null) {
                continue;
            }
            $config = $this->maps->floor($floor, $period, $keys);
            $maps[] = ['name' => (string) $floor['name'], 'image' => SeatMapImage::dataUri($config, 1400)];
        }
        $supplier = $this->settings->supplier();
        $dues = $this->ledger->dues($booking);
        return $this->pdf->render('pdf/allotment-letter', [
            'booking' => $booking,
            'customer' => Customer::safe($customer),
            'seats' => $seats,
            'addons' => $this->bookings->facilities((int) $booking['id']),
            'maps' => $maps,
            'dues' => $dues,
            'deposit' => ($booking['payment_rule'] ?? '') === PaymentRule::SecurityDeposit->value,
            'supplier' => $supplier,
            'logo' => PdfService::imageDataUri($this->settings->imagePath($supplier['logo_path']), 240),
            'signature' => PdfService::imageDataUri($this->settings->imagePath($supplier['signature_path']), 400),
            'seal' => PdfService::imageDataUri($this->settings->imagePath($supplier['seal_path']), 240),
            'qr' => $this->qr->pngDataUri((string) ($customer['unique_id'] ?? $booking['booking_no']), 5),
            'duplicate' => false,
            'today' => $this->clock->today(),
        ]);
    }

    /** @param array<string, mixed> $customer customers row (with unique_id) */
    public function idCard(array $customer): string
    {
        $photo = null;
        $doc = $this->db->first('SELECT * FROM customer_documents WHERE customer_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1', [(int) $customer['id'], DocumentType::Photo->value]);
        if ($doc !== null && str_starts_with((string) $doc['mime'], 'image/')) {
            try {
                $photo = PdfService::imageDataUri($this->files->path($doc), 360, true);
            } catch (\Throwable) {
                $photo = null;
            }
        }
        $supplier = $this->settings->supplier();
        return $this->pdf->render('pdf/id-card', [
            'customer' => Customer::safe($customer),
            'type' => CustomerType::from((string) $customer['type']),
            'photo' => $photo,
            'qr' => $this->qr->pngDataUri((string) $customer['unique_id'], 6),
            'supplier' => $supplier,
            'logo' => PdfService::imageDataUri($this->settings->imagePath($supplier['logo_path']), 160),
            'pageNumbers' => false,
        ], self::CR80);
    }
}
