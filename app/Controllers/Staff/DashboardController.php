<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Enums\StaffRole;
use App\Services\Space\CatalogService;

/**
 * Role-aware dashboard. Each role gets resources/views/staff/dashboard/{role}.php;
 * counts are real queries — future batches replace placeholders with live widgets.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly Database $db, private readonly CatalogService $catalog)
    {
    }

    public function index(): Response
    {
        $user = staff() ?? [];
        $role = StaffRole::from((string) $user['role']);

        $counts = [
            'customers' => (int) $this->db->scalar('SELECT COUNT(*) FROM customers'),
            'kyc_pending' => (int) $this->db->scalar("SELECT COUNT(*) FROM customers WHERE kyc_status = 'pending'"),
            'bookings_requested' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status = 'requested'"),
            'bookings_active' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','active')"),
            'arrivals_today' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE start_date = CURDATE() AND status IN ('confirmed','active')"),
            'payments_pending' => (int) $this->db->scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'payments_pending_amount' => (float) $this->db->scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'pending'"),
            'invoices_this_month' => (int) $this->db->scalar("SELECT COUNT(*) FROM invoices WHERE invoice_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'collected_this_month' => (float) $this->db->scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified' AND paid_on >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'facilities' => (int) $this->db->scalar('SELECT COUNT(*) FROM facilities WHERE is_active = 1'),
            'staff' => (int) $this->db->scalar('SELECT COUNT(*) FROM staff_users WHERE is_active = 1'),
            'centres' => (int) $this->db->scalar('SELECT COUNT(*) FROM centres WHERE is_active = 1'),
        ];

        return $this->view('staff/dashboard/index', [
            'title' => 'Dashboard',
            'user' => $user,
            'role' => $role,
            'counts' => $counts,
            'spaceTypes' => $this->catalog->spaceTypes(),
            'floors' => $this->catalog->floors(),
            'totalSeats' => $this->catalog->totalSeats(),
            'recentLogins' => $this->db->select(
                "SELECT a.created_at, s.name, s.role FROM audit_logs a JOIN staff_users s ON s.id = a.actor_id
                 WHERE a.action = 'staff.login' ORDER BY a.id DESC LIMIT 5",
            ),
        ]);
    }
}
