<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\PaymentStatus;
use App\Enums\StaffRole;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingNotifier;
use App\Services\Notify\Mailer;
use App\Support\Clock;

/**
 * Finance's payment verification queue (/staff/finance/payments, spec 6.4). Front desk logs payments (status
 * `logged`, PaymentService); Finance then
 *
 *   verify()      logged → verified: verified_by/at, audit `payment.verify`, and the receipt RCPT/{FY}/{n} is issued
 *                 in the same transaction (ReceiptService). bulkVerify() does it per payment (one failure never
 *                 blocks the rest).
 *   query()       sends a logged payment back to the front desk with a note (flag + notification + email); it stays
 *                 in the queue marked "queried" until the desk replies (resolve()) or Finance verifies it anyway.
 *   void          stays a Centre Manager action (PaymentService::void(), ability payments.void).
 */
final class PaymentVerificationService
{
    public const SORTS = ['oldest' => 'Oldest first', 'amount' => 'Largest amount', 'mode' => 'Payment mode', 'newest' => 'Newest first'];

    public function __construct(
        private readonly Database $db,
        private readonly ReceiptService $receipts,
        private readonly BookingNotifier $notifier,
        private readonly Mailer $mailer,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string, string> $f status (pending|queried|verified|all), mode, kind, q, from, to, sort
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int, sum: float}
     */
    public function queue(array $f, int $page = 1, int $perPage = 25): array
    {
        [$where, $bind] = $this->filters($f);
        $order = match ($f['sort'] ?? 'oldest') {
            'amount' => 'p.amount DESC, p.id',
            'mode' => 'p.mode, p.paid_on, p.id',
            'newest' => 'p.created_at DESC, p.id DESC',
            default => 'p.paid_on, p.created_at, p.id',
        };
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN customers c ON c.id = p.customer_id WHERE {$where}", $bind);
        $sum = (float) $this->db->scalar("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN customers c ON c.id = p.customer_id WHERE {$where}", $bind);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->select(
            "SELECT p.*, b.booking_no, b.status AS booking_status, b.grand_total, b.payment_rule, c.name AS customer_name, c.unique_id,
                    l.name AS logged_by_name, v.name AS verified_by_name, q.name AS queried_by_name, rs.name AS resolved_by_name,
                    r.id AS receipt_id, r.receipt_no, DATEDIFF(?, p.paid_on) AS age_days
             FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN customers c ON c.id = p.customer_id
             LEFT JOIN staff_users l ON l.id = p.logged_by LEFT JOIN staff_users v ON v.id = p.verified_by
             LEFT JOIN staff_users q ON q.id = p.queried_by LEFT JOIN staff_users rs ON rs.id = p.query_resolved_by
             LEFT JOIN receipts r ON r.payment_id = p.id
             WHERE {$where} ORDER BY {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            [$this->clock->today(), ...$bind],
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'sum' => round($sum, 2)];
    }

    /** @return array{pending: int, queried: int, verified_today: int, pending_amount: float, oldest_days: int} */
    public function counts(): array
    {
        $today = $this->clock->today();
        return [
            'pending' => (int) $this->db->scalar("SELECT COUNT(*) FROM payments WHERE status = 'logged'"),
            'queried' => (int) $this->db->scalar("SELECT COUNT(*) FROM payments WHERE status = 'logged' AND queried_at IS NOT NULL AND query_resolved_at IS NULL"),
            'verified_today' => (int) $this->db->scalar("SELECT COUNT(*) FROM payments WHERE status = 'verified' AND DATE(verified_at) = ?", [$today]),
            'pending_amount' => round((float) $this->db->scalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'logged'"), 2),
            'oldest_days' => (int) $this->db->scalar("SELECT COALESCE(MAX(DATEDIFF(?, paid_on)), 0) FROM payments WHERE status = 'logged'", [$today]),
        ];
    }

    /**
     * @return array{payment: array<string, mixed>, receipt: array<string, mixed>}
     */
    public function verify(int $paymentId, Actor $actor, ?string $note = null): array
    {
        if (!$actor->can('payments.verify')) {
            throw new FinanceException('Only Finance can verify payments.', 'permission');
        }
        return $this->db->transaction(function (Database $db) use ($paymentId, $actor, $note): array {
            $p = $db->first('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]) ?? throw new FinanceException('Payment not found.', 'input');
            if ($p['status'] !== PaymentStatus::Logged->value) {
                throw new FinanceException(sprintf('Payment #%d is %s — only logged payments can be verified.', $paymentId, strtolower(PaymentStatus::from((string) $p['status'])->label())));
            }
            $now = $this->clock->sql();
            $fields = ['status' => PaymentStatus::Verified->value, 'verified_by' => $actor->staffId(), 'verified_at' => $now];
            if ($p['queried_at'] !== null && $p['query_resolved_at'] === null) {
                $fields += ['query_resolved_at' => $now, 'query_resolved_by' => $actor->staffId()];
            }
            $db->update('payments', $fields, ['id' => $paymentId]);
            $this->audit->record('payment.verify', 'booking', (int) $p['booking_id'], ['payment_id' => $paymentId, 'status' => $p['status']], [
                'status' => PaymentStatus::Verified->value, 'amount' => (float) $p['amount'], 'mode' => $p['mode'], 'reference_no' => $p['reference_no'],
            ], $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'staff', $actor->id);
            $receipt = $this->receipts->issueForPayment($paymentId, $actor->staffId());
            return ['payment' => (array) $db->first('SELECT * FROM payments WHERE id = ?', [$paymentId]), 'receipt' => $receipt];
        });
    }

    /**
     * @param list<int> $ids
     * @return array{verified: list<array<string, mixed>>, failed: array<int, string>}
     */
    public function bulkVerify(array $ids, Actor $actor): array
    {
        $out = ['verified' => [], 'failed' => []];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $out['verified'][] = $this->verify($id, $actor);
            } catch (FinanceException $e) {
                $out['failed'][$id] = $e->getMessage();
            }
        }
        return $out;
    }

    /** Send a logged payment back to the front desk with a question. */
    public function query(int $paymentId, Actor $actor, string $note): void
    {
        if (!$actor->can('payments.verify')) {
            throw new FinanceException('Only Finance can query payments.', 'permission');
        }
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new FinanceException('Write a short note for the front desk (at least 5 characters).', 'input');
        }
        $p = $this->db->transaction(function (Database $db) use ($paymentId, $actor, $note): array {
            $p = $db->first('SELECT p.*, b.booking_no, b.centre_id FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? FOR UPDATE', [$paymentId])
                ?? throw new FinanceException('Payment not found.', 'input');
            if ($p['status'] !== PaymentStatus::Logged->value) {
                throw new FinanceException('Only logged (unverified) payments can be queried.');
            }
            $db->update('payments', [
                'query_note' => mb_substr($note, 0, 500), 'queried_by' => $actor->staffId(), 'queried_at' => $this->clock->sql(),
                'query_reply' => null, 'query_resolved_by' => null, 'query_resolved_at' => null,
            ], ['id' => $paymentId]);
            $this->audit->record('payment.query', 'booking', (int) $p['booking_id'], null, ['payment_id' => $paymentId], $note, 'staff', $actor->id);
            return $p;
        });
        $this->notifyStaff(
            [StaffRole::Receptionist, StaffRole::CentreManager],
            (int) $p['centre_id'],
            $actor,
            sprintf('Finance query on %s payment of %s', $p['booking_no'], money($p['amount'], 2)),
            sprintf('%s asked about the %s payment of %s (ref %s) on %s: “%s” Reply from the booking page.', $actor->name !== '' ? $actor->name : 'Finance', strtoupper((string) $p['mode']), money($p['amount'], 2), $p['reference_no'] ?: '—', $p['booking_no'], $note),
            (string) $p['booking_no'],
            'payment.query',
        );
    }

    /** Front desk answers a Finance query (clears the flag; the payment returns to the normal queue). */
    public function resolve(int $paymentId, Actor $actor, string $reply): void
    {
        if (!$actor->can('payments.log')) {
            throw new FinanceException('Your role cannot answer payment queries.', 'permission');
        }
        $reply = trim($reply);
        if (mb_strlen($reply) < 2) {
            throw new FinanceException('Write a reply for Finance.', 'input');
        }
        $p = $this->db->transaction(function (Database $db) use ($paymentId, $actor, $reply): array {
            $p = $db->first('SELECT p.*, b.booking_no, b.centre_id FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ? FOR UPDATE', [$paymentId])
                ?? throw new FinanceException('Payment not found.', 'input');
            if ($p['queried_at'] === null || $p['query_resolved_at'] !== null) {
                throw new FinanceException('There is no open Finance query on this payment.');
            }
            $db->update('payments', ['query_reply' => mb_substr($reply, 0, 500), 'query_resolved_by' => $actor->staffId(), 'query_resolved_at' => $this->clock->sql()], ['id' => $paymentId]);
            $this->audit->record('payment.query_reply', 'booking', (int) $p['booking_id'], null, ['payment_id' => $paymentId], $reply, 'staff', $actor->id);
            return $p;
        });
        $this->notifyStaff(
            [StaffRole::FinanceAdmin],
            (int) $p['centre_id'],
            $actor,
            sprintf('Front desk replied on %s payment of %s', $p['booking_no'], money($p['amount'], 2)),
            sprintf('%s replied to your query “%s”: “%s”', $actor->name !== '' ? $actor->name : 'The front desk', (string) $p['query_note'], $reply),
            (string) $p['booking_no'],
            'payment.query_reply',
        );
    }

    /**
     * @param list<StaffRole> $roles
     */
    private function notifyStaff(array $roles, int $centreId, Actor $actor, string $subject, string $message, string $bookingNo, string $type): void
    {
        $in = implode(',', array_fill(0, count($roles), '?'));
        $staff = $this->db->select(
            "SELECT id, name, email FROM staff_users WHERE is_active = 1 AND role IN ({$in}) AND (centre_id IS NULL OR centre_id = ?)",
            [...array_map(static fn (StaffRole $r) => $r->value, $roles), $centreId],
        );
        foreach ($staff as $s) {
            if ((int) $s['id'] === $actor->id) {
                continue;
            }
            $this->notifier->log('staff', (int) $s['id'], $type, $subject, $message, ['booking_no' => $bookingNo], null, 'database,email');
            $this->mailer->send([(string) $s['email'], (string) $s['name']], $subject, 'staff-booking-update', [
                'name' => (string) $s['name'],
                'booking' => ['booking_no' => $bookingNo],
                'message' => $message,
                'url' => absolute_url('staff.bookings.show', ['no' => $bookingNo]) . '#payments',
            ]);
        }
    }

    /**
     * @param array<string, string> $f
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(array $f): array
    {
        $w = ['1 = 1'];
        $bind = [];
        switch ($f['status'] ?? 'pending') {
            case 'queried':
                $w[] = "p.status = 'logged' AND p.queried_at IS NOT NULL AND p.query_resolved_at IS NULL";
                break;
            case 'verified':
                $w[] = "p.status = 'verified'";
                break;
            case 'void':
                $w[] = "p.status IN ('void', 'rejected', 'refunded')";
                break;
            case 'all':
                break;
            default:
                $w[] = "p.status = 'logged'";
        }
        if (($f['mode'] ?? '') !== '') {
            $w[] = 'p.mode = ?';
            $bind[] = $f['mode'];
        }
        if (($f['kind'] ?? '') !== '') {
            $w[] = 'p.kind = ?';
            $bind[] = $f['kind'];
        }
        if (($f['from'] ?? '') !== '') {
            $w[] = 'p.paid_on >= ?';
            $bind[] = $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $w[] = 'p.paid_on <= ?';
            $bind[] = $f['to'];
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($f['q'])) . '%';
            $w[] = '(b.booking_no LIKE ? OR c.name LIKE ? OR c.unique_id LIKE ? OR p.reference_no LIKE ?)';
            array_push($bind, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $bind];
    }
}
