<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Core\Database;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CreditNoteReason;
use App\Enums\CustomerType;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\BookingTicker;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Bookings\RenewalService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentVerificationService;
use App\Services\Kyc\IdValidator;
use App\Services\Kyc\KycReviewService;
use App\Services\Kyc\Verhoeff;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Visitors\VisitorRegistration;
use App\Support\Clock;
use DateTimeImmutable;
use RuntimeException;

/**
 * `php bin/console demo:seed` — a realistic spread of UAT data built ONLY through the real services (the same code
 * paths as the UI), with the Clock frozen at each event's date so history looks natural:
 *
 *   ~30 visitors (VisitorRegistration: individuals of every sub-category, institutions of every type, online vs
 *   reception, Kerala + other states + one foreign national; KYC verified / pending / rejected / not submitted)
 *   ~40 bookings over the last ~3 months and the next few weeks (BookingService): flexi day passes and months,
 *   dedicated seats (advance and > 6-month security-deposit tenures), cabins, conference-room hours, online requests
 *   (approved / rejected / cancelled / still pending), approved-awaiting-payment
 *   payments (PaymentService) — most verified by Finance (receipts), some left to verify, one queried; some rent
 *   periods deliberately unpaid so dues ageing has 0–30 / 31–60 / 61–90 buckets
 *   invoices for every verified source (InvoiceService), a credit note, a renewal, then BookingTicker at "today"
 *
 * Refuses to run when APP_ENV=production (checked by the console) and when demo visitors already exist.
 */
final class DemoSeeder
{
    public const EMAIL_DOMAIN = 'demo.commune.test';

    /** @var array<string, string> seat code => last busy day */
    private array $busy = [];
    /** @var list<array<string, mixed>> */
    private array $customers = [];
    private int $seq = 0;
    private string $today = '';
    /** @var \Closure(string): void */
    private \Closure $log;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly VisitorRegistration $visitors,
        private readonly KycReviewService $kyc,
        private readonly BookingService $bookings,
        private readonly BookingWorkflow $workflow,
        private readonly PaymentService $payments,
        private readonly PaymentLedger $ledger,
        private readonly PaymentVerificationService $verification,
        private readonly InvoiceService $invoices,
        private readonly CreditNoteService $creditNotes,
        private readonly RenewalService $renewals,
        private readonly BookingTicker $ticker,
    ) {
        $this->log = static function (string $l): void {
        };
    }

    /**
     * @param callable(string): void|null $log
     * @return array<string, int>
     */
    public function run(?callable $log = null): array
    {
        if ($log !== null) {
            $this->log = \Closure::fromCallable($log);
        }
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM customers WHERE email LIKE ?', ['%@' . self::EMAIL_DOMAIN]) > 0) {
            throw new RuntimeException('Demo data is already loaded (visitors @' . self::EMAIL_DOMAIN . ' exist). Run migrate:fresh --seed first.');
        }
        mt_srand(2026);
        $today = $this->clock->today();
        $this->today = $today;
        $t = new DateTimeImmutable($today);
        $d = static fn (int $days): string => $t->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

        $staff = [];
        foreach (['receptionist', 'centre_manager', 'finance_admin'] as $role) {
            $staff[$role] = (array) ($this->db->first('SELECT * FROM staff_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1', [$role]) ?? throw new RuntimeException("No active {$role} — run the seeders first."));
        }
        $desk = Actor::staff($staff['receptionist']);
        $manager = Actor::staff($staff['centre_manager']);
        $finance = Actor::staff($staff['finance_admin']);

        // ------------------------------------------------------------ visitors
        ($this->log)('Registering visitors…');
        foreach ($this->people() as $i => $p) {
            $registered = $d(-105 + $i * 3);
            $this->at($registered . ' 10:30:00');
            $type = CustomerType::from($p['type']);
            $input = $this->input($p, $i);
            $staffRow = $p['via'] === 'online' ? [] : $staff['receptionist'];
            if ($p['kyc'] === 'not_submitted') {
                $this->visitors->validate($type, $input);
                $id = $this->visitors->create($type, $input, $staffRow, $p['via']);
                $uid = null;
            } else {
                $r = $this->visitors->register($type, $input, $staffRow, $p['via'], verify: false);
                $id = $r['id'];
                $uid = $r['unique_id'];
                if ($p['kyc'] === 'verified') {
                    $this->at((new DateTimeImmutable($registered))->modify('+' . (1 + $i % 3) . ' days')->format('Y-m-d') . ' 11:15:00');
                    $this->kyc->approve($id, (int) $staff['centre_manager']['id'], 'Documents checked at the desk.');
                } elseif ($p['kyc'] === 'rejected') {
                    $this->kyc->reject($id, (int) $staff['centre_manager']['id'], 'The PAN card copy is unreadable — please upload a clearer scan.');
                }
            }
            $this->db->execute(
                'UPDATE customers SET created_at = ?, kyc_submitted_at = IF(kyc_submitted_at IS NULL, NULL, ?), kyc_verified_at = IF(kyc_verified_at IS NULL, NULL, ?) WHERE id = ?',
                [$registered . ' 10:30:00', $registered . ' 10:45:00', (new DateTimeImmutable($registered))->modify('+' . (1 + $i % 3) . ' days')->format('Y-m-d') . ' 11:15:00', $id],
            );
            $this->customers[] = ['id' => $id, 'unique_id' => $uid, 'kyc' => $p['kyc'], 'type' => $p['type'], 'name' => $p['name']];
        }
        $verified = array_values(array_filter($this->customers, static fn (array $c) => $c['kyc'] === 'verified'));
        $cust = fn (int $n): array => $verified[$n % count($verified)];

        // ------------------------------------------------------------ bookings (created in chronological order)
        ($this->log)('Creating bookings, payments, receipts and invoices…');
        $plans = [
            // [customer#, category, seats, start offset, end offset | hours, created offset, payment plan, addons]
            [0, 'FLEXI', 1, -92, -88, -95, 'full', []],
            [1, 'FLEXI', 1, -90, -61, -93, 'full', [6 => 1]],
            [2, 'DEDICATED', 1, -88, -1, -90, 'full', []],
            [3, 'CABIN', 1, -86, 240, -90, 'deposit-behind', [7 => 1]],        // 6+ months: deposit + rent, periods unpaid → ageing
            [4, 'DEDICATED', 4, -80, 290, -84, 'deposit-current', [10 => 1]],  // institution, 12 months
            [5, 'FLEXI', 1, -75, -75, -76, 'full', []],
            [6, 'CONF', 1, -70, [10, 13], -72, 'full', []],
            [7, 'FLEXI', 2, -68, -39, -70, 'full', [6 => 2]],
            [8, 'DEDICATED', 2, -62, 28, -65, 'full', []],
            [9, 'FLEXI', 1, -60, -58, -61, 'full', []],
            [10, 'CABIN', 1, -58, 33, -60, 'full', []],
            [11, 'FLEXI', 1, -52, -23, -55, 'full', [7 => 1]],
            [0, 'CONF', 1, -48, [14, 17], -50, 'full', [9 => 1]],
            [12, 'DEDICATED', 3, -45, 260, -48, 'deposit-one-behind', []],
            [18, 'DEDICATED', 6, -55, 170, -58, 'full', [10 => 1]],          // institution team, 7+ months paid ahead
            [21, 'FLEXI', 3, -33, -4, -35, 'full', []],
            [13, 'FLEXI', 1, -40, -36, -41, 'full', []],
            [14, 'FLEXI', 1, -35, -6, -37, 'full', []],
            [1, 'FLEXI', 1, -30, 12, -31, 'full', [6 => 1]],
            [15, 'DEDICATED', 1, -28, 62, -30, 'full', []],
            [16, 'CONF', 1, -25, [9, 12], -26, 'full', []],
            [17, 'FLEXI', 1, -21, -17, -22, 'full', []],
            [5, 'DEDICATED', 2, -20, 9, -22, 'full', [7 => 1]],
            [18, 'CABIN', 1, -18, 11, -20, 'full', []],
            [19, 'FLEXI', 1, -15, 14, -16, 'full', []],
            [2, 'FLEXI', 1, 0, 0, -1, 'full', []],
            [9, 'DEDICATED', 1, -12, 5, -14, 'full', []],
            [20, 'FLEXI', 1, -10, 19, -11, 'logged', []],                       // paid, not yet verified
            [13, 'CONF', 1, -8, [15, 18], -9, 'full', []],
            [6, 'FLEXI', 1, -6, 3, -7, 'logged', []],
            [21, 'DEDICATED', 1, -5, 24, -6, 'queried', []],                    // Finance queried the payment
            [11, 'FLEXI', 1, -4, 25, -5, 'full', []],
            [3, 'FLEXI', 1, -3, -2, -4, 'full', []],
            [14, 'DEDICATED', 1, 2, 91, -2, 'full', []],                        // upcoming, confirmed
            [7, 'CONF', 1, 3, [10, 12], -1, 'full', []],
            [17, 'FLEXI', 1, 4, 33, -1, 'none', []],                            // approved, awaiting payment
            [19, 'DEDICATED', 2, 7, 96, -2, 'none', []],                        // approved, awaiting payment
        ];
        $created = [];
        usort($plans, static fn (array $a, array $b) => $a[5] <=> $b[5]);
        foreach ($plans as [$cn, $cat, $n, $start, $end, $createdAt, $pay, $addons]) {
            $c = $cust($cn);
            $this->at($d($createdAt) . ' ' . sprintf('%02d:%02d:00', 9 + $this->seq % 8, (7 * $this->seq) % 60));
            $period = is_array($end)
                ? BookingPeriod::hours($d($start), sprintf('%02d:00', $end[0]), sprintf('%02d:00', $end[1]))
                : BookingPeriod::days($d($start), $d($end));
            $b = $this->book($c, $cat, $n, $period, $addons, $staff['receptionist']);
            if ($b === null) {
                continue;
            }
            $created[] = $b;
            $this->pay($b, $pay, $desk, $finance, $manager);
        }

        // online requests: pending, approved later, rejected, cancelled
        ($this->log)('Online requests…');
        $online = [[22, 'FLEXI', 1, 5, 34, -3, 'pending'], [23, 'DEDICATED', 2, 10, 99, -2, 'pending'], [8, 'FLEXI', 1, 1, 1, -1, 'pending'],
            [16, 'DEDICATED', 1, -25, 60, -30, 'approve-pay'], [12, 'FLEXI', 1, -40, -38, -44, 'reject'], [10, 'FLEXI', 1, 6, 8, -5, 'cancel']];
        foreach ($online as [$cn, $cat, $n, $start, $end, $createdAt, $what]) {
            $c = $cust($cn);
            $this->at($d($createdAt) . ' 19:40:00');
            $b = $this->book($c, $cat, $n, BookingPeriod::days($d($start), $d($end)), [], null);
            if ($b === null) {
                continue;
            }
            $this->at($d($createdAt + 1) . ' 10:05:00');
            match ($what) {
                'approve-pay' => (function () use ($b, $manager, $desk, $finance): void {
                    $this->workflow->approve((int) $b['id'], $manager, 'Welcome aboard.');
                    $this->pay($this->workflow->find((int) $b['id']), 'full', $desk, $finance, $manager);
                })(),
                'reject' => $this->workflow->reject((int) $b['id'], $manager, 'Those dates are reserved for a K-DISC programme.'),
                'cancel' => $this->workflow->cancel((int) $b['id'], $manager, 'The visitor called to cancel.'),
                default => null,
            };
        }

        // ------------------------------------------------------------ today: lifecycle, renewal, credit note
        $this->at($today . ' ' . $this->clock->now()->format('H:i:s'));
        $this->clock->freeze(null);
        ($this->log)('Running the booking ticker for today…');
        $tick = $this->ticker->run();

        $renewed = 0;
        foreach ($this->db->select(
            "SELECT * FROM bookings WHERE status = 'active' AND start_time IS NULL AND end_date BETWEEN ? AND ? AND payment_rule = 'advance' ORDER BY end_date",
            [$d(3), $d(25)],
        ) as $renewable) {
            try {
                $r = $this->renewals->extend($renewable, (new DateTimeImmutable((string) $renewable['end_date']))->modify('+1 month')->format('Y-m-d'), [], $desk, 'Renewed at the desk.');
                $this->pay($r['booking'], 'full', $desk, $finance, $manager);
                $renewed = 1;
                break;
            } catch (\Throwable) {
                continue; // seats taken later on — try the next one
            }
        }
        $credit = 0;
        $inv = $this->db->first("SELECT i.* FROM invoices i JOIN bookings b ON b.id = i.booking_id WHERE b.status = 'active' AND i.kind = 'advance' AND i.status = 'issued' ORDER BY i.total DESC LIMIT 1");
        if ($inv !== null) {
            $this->creditNotes->issue((int) $inv['id'], CreditNoteReason::Discount, 'Goodwill discount — air conditioning outage in week 2.', round((float) $inv['taxable_value'] * 0.1, 2), $finance);
            $credit = 1;
        }

        return [
            'visitors' => count($this->customers),
            'bookings' => (int) $this->db->scalar('SELECT COUNT(*) FROM bookings'),
            'payments' => (int) $this->db->scalar('SELECT COUNT(*) FROM payments'),
            'invoices' => (int) $this->db->scalar('SELECT COUNT(*) FROM invoices'),
            'receipts' => (int) $this->db->scalar('SELECT COUNT(*) FROM receipts'),
            'credit_notes' => $credit,
            'renewals' => $renewed,
            'activated' => $tick['activated'],
            'completed' => $tick['completed'],
            'expired' => $tick['expired'],
        ];
    }

    private function at(string $when): void
    {
        $this->clock->freeze(new DateTimeImmutable($when));
    }

    /**
     * Pick free seats of the category (no overlap with earlier demo bookings) and create the booking.
     *
     * @param array<string, mixed> $c demo customer
     * @param array<int, int> $addons
     * @param array<string, mixed>|null $staff null = online request
     * @return array<string, mixed>|null
     */
    private function book(array $c, string $cat, int $n, BookingPeriod $period, array $addons, ?array $staff): ?array
    {
        $codes = $this->db->column(
            "SELECT s.code FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             JOIN seat_categories sc ON sc.id = z.seat_category_id JOIN floors f ON f.id = lv.floor_id
             WHERE sc.code = ? AND s.parent_id IS NULL ORDER BY f.sort_order, s.code",
            [$cat],
        );
        shuffle($codes); // seeded (mt_srand) — spreads demo bookings over both floors
        $pick = [];
        $keyFor = static fn (string $code): string => $code . ($period->isHourly() ? '@' . $period->from . $period->startTime : '');
        foreach ($codes as $code) {
            $k = $keyFor((string) $code);
            if (($this->busy[$k] ?? '0000-00-00') < $period->from) {
                $pick[] = (string) $code;
                if (count($pick) === $n) {
                    break;
                }
            }
        }
        if (count($pick) < $n) {
            ($this->log)("  (no free {$cat} seats for {$period->from})");
            return null;
        }
        $ids = array_map(fn (string $code) => (int) $this->db->scalar(
            "SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' WHERE s.code = ?",
            [$code],
        ), $pick);
        $customer = (array) $this->db->first('SELECT * FROM customers WHERE id = ?', [$c['id']]);
        $holder = $staff !== null
            ? SeatHolder::staff((int) $staff['id'], 'demo-' . (++$this->seq), (int) $c['id'])
            : SeatHolder::account((int) ($customer['account_id'] ?? 0), 'demo-' . (++$this->seq));
        $r = $this->bookings->create($customer, $holder, $ids, $period, $addons, $staff !== null ? BookingSource::Reception : BookingSource::Online, $staff !== null
            ? ['status' => BookingStatus::Approved, 'created_by' => (int) $staff['id'], 'terms' => true]
            : ['status' => BookingStatus::Requested, 'terms' => true]);
        foreach ($pick as $code) {
            $this->busy[$keyFor($code)] = $period->to;
        }
        $this->db->execute('UPDATE bookings SET created_at = ? WHERE id = ?', [$this->clock->sql(), $r['booking']['id']]);
        return $r['booking'];
    }

    /**
     * Payment plans: full (everything due now, verified + invoiced) | logged (paid, awaiting Finance) | queried |
     * deposit-current (deposit + every due period) | deposit-one-behind (latest period unpaid) | deposit-behind (only
     * deposit + first period: later periods age) | none (approved, awaiting payment).
     *
     * @param array<string, mixed> $b
     */
    private function pay(array $b, string $plan, Actor $desk, Actor $finance, Actor $manager): void
    {
        if ($plan === 'none') {
            return;
        }
        $b = $this->workflow->find((int) $b['id']);
        $modes = [['upi', 'UPI/6271' . random_int(10000000, 99999999)], ['bank_transfer', 'NEFT SBIN' . random_int(100000000, 999999999)], ['cash', ''], ['card', 'APPR' . random_int(100000, 999999)], ['cheque', 'CHQ ' . random_int(100000, 999999) . ' SBI']];
        $mode = $modes[(int) $b['id'] % count($modes)];
        $ids = [];
        $log = function (string $kind, float $amount) use ($b, $desk, $mode, &$ids): void {
            if ($amount <= 0) {
                return;
            }
            $r = $this->payments->log($this->workflow->find((int) $b['id']), ['kind' => $kind, 'mode' => $mode[0], 'reference_no' => $mode[1], 'amount' => $amount, 'paid_on' => $this->clock->today(), 'remarks' => ''], $desk);
            $this->db->execute('UPDATE payments SET created_at = ? WHERE id = ?', [$this->clock->sql(), $r['payment']['id']]);
            $ids[] = (int) $r['payment']['id'];
        };
        if ($b['payment_rule'] === 'advance') {
            $log('advance', (float) $b['grand_total']);
        } else {
            $dues = $this->ledger->dues($b);
            $log('deposit', (float) $b['deposit_amount']);
            $schedule = $dues['schedule'];
            // the demo clock moves forward later: pay the periods that are due by the seeding day, as the plan says
            $realToday = $this->today;
            foreach ($schedule as $i => $p) {
                $due = (string) $p['due_on'];
                $pay = match ($plan) {
                    'deposit-behind' => $i === 0,
                    'deposit-one-behind' => $i === 0 || ($due <= $realToday && $i < count(array_filter($schedule, static fn (array $x) => (string) $x['due_on'] <= $realToday)) - 1),
                    default => $i === 0 || $due <= $realToday,
                };
                if ($pay) {
                    $log('rent', (float) $p['amount']);
                }
            }
        }
        if ($plan === 'logged') {
            return;
        }
        foreach ($ids as $pid) {
            if ($plan === 'queried') {
                $this->verification->query($pid, $finance, 'The UPI reference does not appear in the bank statement — please re-check with the visitor.');
                return;
            }
            $this->verification->verify($pid, $finance);
        }
        foreach ($this->invoices->candidates(array_merge($this->workflow->find((int) $b['id']), ['customer_state' => $this->db->scalar('SELECT state_code FROM customers WHERE id = ?', [$b['customer_id']])])) as $cand) {
            if (!$cand['invoiced']) {
                $this->invoices->issue((int) $b['id'], (string) $cand['source_key'], $finance);
            }
        }
        unset($manager);
    }

    /** @return list<array<string, string>> */
    private function people(): array
    {
        $ind = static fn (string $name, string $sub, string $via, string $kyc, string $state = '32', array $extra = []) => ['type' => 'individual', 'name' => $name, 'sub' => $sub, 'via' => $via, 'kyc' => $kyc, 'state' => $state] + $extra;
        $inst = static fn (string $name, string $sub, string $via, string $kyc, string $state = '32') => ['type' => 'institution', 'name' => $name, 'sub' => $sub, 'via' => $via, 'kyc' => $kyc, 'state' => $state];
        return [
            $ind('Anjali Pillai', 'freelancer', 'online', 'verified'),
            $ind('Rahul Menon', 'remote_worker', 'reception', 'verified'),
            $ind('Meera Nair', 'self_employed', 'online', 'verified'),
            $inst('Kollam Code Labs LLP', 'startup', 'reception', 'verified'),
            $inst('Travancore Analytics Pvt Ltd', 'company', 'reception', 'verified'),
            $ind('Arjun Krishnan', 'student', 'online', 'verified'),
            $ind('Fathima Rasheed', 'freelancer', 'reception', 'verified'),
            $ind('Vishnu Prasad', 'remote_worker', 'online', 'verified'),
            $inst('Marina Robotics Pvt Ltd', 'company', 'reception', 'verified', '33'),
            $ind('Lakshmi Varma', 'self_employed', 'reception', 'verified'),
            $inst('Nila Foundation', 'ngo', 'online', 'verified'),
            $ind('Sreejith Kumar', 'remote_worker', 'reception', 'verified', '29'),
            $inst('Kerala Startup Collective', 'startup', 'online', 'verified'),
            $ind('Neha Joseph', 'student', 'reception', 'verified'),
            $ind('Aditya Rao', 'freelancer', 'online', 'verified', '27'),
            $inst('Kottarakara Block Panchayat Office', 'govt_body', 'reception', 'verified'),
            $ind('Deepa Mohan', 'self_employed', 'reception', 'verified'),
            $ind('Emily Carter', 'remote_worker', 'online', 'verified', '32', ['foreign' => 'United Kingdom']),
            $inst('Periyar Institute of Design', 'institution', 'reception', 'verified'),
            $ind('Nikhil Thomas', 'freelancer', 'online', 'verified'),
            $ind('Anu George', 'student', 'online', 'verified'),
            $inst('Ashtamudi Software Solutions', 'company', 'reception', 'verified'),
            $ind('Gokul Das', 'remote_worker', 'reception', 'verified'),
            $ind('Haritha S', 'self_employed', 'online', 'verified'),
            $ind('Joel Mathew', 'student', 'online', 'pending'),
            $inst('Punalur Paper Works Ltd', 'company', 'reception', 'pending'),
            $ind('Keerthi Raj', 'freelancer', 'online', 'pending'),
            $ind('Manoj Unnikrishnan', 'remote_worker', 'online', 'rejected'),
            $ind('Sana Ibrahim', 'student', 'online', 'not_submitted'),
            $inst('Chadayamangalam Farmers Co-op', 'ngo', 'reception', 'not_submitted'),
        ];
    }

    /**
     * Form-shaped input for one demo visitor with valid checksummed identifiers.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function input(array $p, int $i): array
    {
        $slug = strtolower((string) preg_replace('/[^a-z]+/i', '.', (string) $p['name']));
        $slug = trim($slug, '.');
        $email = $slug . '@' . self::EMAIL_DOMAIN;
        $mobile = '+9194' . sprintf('%08d', 47000000 + $i * 7919);
        $cities = ['32' => ['Kottarakara', '691506'], '33' => ['Chennai', '600113'], '29' => ['Bengaluru', '560034'], '27' => ['Pune', '411001']];
        [$city, $pin] = $cities[$p['state']] ?? $cities['32'];
        $letters = 'ABCDEFGHJKLMNPRSTUVWXYZ';
        $l = static fn (int $k) => $letters[$k % strlen($letters)];
        $surname = strtoupper(substr((string) preg_replace('/[^A-Z]/i', '', (string) (explode(' ', (string) $p['name'])[1] ?? $p['name'])), 0, 1)) ?: 'K';
        $base = [
            'sub_category' => $p['sub'], 'name' => $p['name'], 'email' => $email, 'mobile' => $mobile,
            'address' => sprintf('%d/%d, %s Road, near the bus stand', 10 + $i, 200 + $i * 3, ['Temple', 'Market', 'Station', 'College', 'Church'][$i % 5]),
            'city' => $city, 'pincode' => $pin, 'state_code' => $p['state'],
        ];
        if ($p['type'] === 'individual') {
            $pan = $l($i) . $l($i + 3) . $l($i + 7) . 'P' . $surname . sprintf('%04d', 1000 + $i * 37) . $l($i + 11);
            $aadhaar = (string) (234100000000 + $i * 7919013) ;
            $aadhaar = substr($aadhaar, 0, 11);
            $aadhaar .= Verhoeff::checkDigit($aadhaar);
            $foreign = isset($p['foreign']);
            return $base + [
                'profile' => sprintf('%s based in %s, working on product design and consulting projects for clients across India.', ucfirst(str_replace('_', ' ', (string) $p['sub'])), $city),
                'nationality_type' => $foreign ? 'foreign' : 'indian',
                'country' => $foreign ? $p['foreign'] : '',
                'passport_no' => $foreign ? 'N' . sprintf('%07d', 4812000 + $i) : '',
                'aadhaar' => $foreign ? '' : $aadhaar,
                'aadhaar_consent' => $foreign ? '' : '1',
                'pan' => $i % 3 === 0 ? $pan : '',
                'gstin' => '',
            ];
        }
        $kind = match ($p['sub']) {
            'company', 'startup' => 'C',
            'ngo' => 'T',
            'govt_body' => 'G',
            default => 'A',
        };
        $pan = 'AA' . $l($i + 5) . $kind . strtoupper(substr((string) $p['name'], 0, 1)) . sprintf('%04d', 4000 + $i * 53) . $l($i + 2);
        $gst14 = $p['state'] . $pan . '1Z';
        $sigAadhaar = substr((string) (345600000000 + $i * 6007001), 0, 11);
        return $base + [
            'profile' => sprintf('%s is a %s in %s working on software, research and community programmes. The team of %d uses the centre for focused project work, client meetings and training sessions.',
                $p['name'], str_replace('_', ' ', (string) $p['sub']), $city, 3 + $i % 9),
            'pan' => $pan,
            'gstin' => $gst14 . IdValidator::gstinCheckChar($gst14),
            'tan' => 'TVD' . $l($i) . sprintf('%05d', 10000 + $i * 211) . $l($i + 4),
            'sig_name' => ['Suresh Babu', 'Priya Menon', 'Thomas Kurian', 'Asha Nair', 'Ravi Shankar'][$i % 5],
            'sig_designation' => ['Director', 'Managing Partner', 'CEO', 'Secretary', 'Administrator'][$i % 5],
            'sig_email' => 'signatory.' . $slug . '@' . self::EMAIL_DOMAIN,
            'sig_mobile' => '+9198' . sprintf('%08d', 46000000 + $i * 6007),
            'sig_aadhaar' => $sigAadhaar . Verhoeff::checkDigit($sigAadhaar),
            'aadhaar_consent' => '1',
        ];
    }
}
