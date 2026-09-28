<?php

declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Database;
use App\Support\Clock;

/**
 * Staff notification inbox over `notifications` (recipient_type = 'staff'), written by BookingNotifier (booking
 * changes, Finance payment queries, …). Header bell (unread count + latest) is shared into every staff page by the
 * StaffAuth middleware; the full list lives at /staff/notifications.
 */
final class StaffInbox
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function unreadCount(int $staffId): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE recipient_type = 'staff' AND recipient_id = ? AND read_at IS NULL", [$staffId]);
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $staffId, int $limit = 6): array
    {
        return array_map(fn (array $n) => $this->decorate($n), $this->db->select(
            "SELECT * FROM notifications WHERE recipient_type = 'staff' AND recipient_id = ? ORDER BY id DESC LIMIT " . max(1, $limit),
            [$staffId],
        ));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function page(int $staffId, bool $unreadOnly, int $page = 1, int $perPage = 25): array
    {
        $where = "recipient_type = 'staff' AND recipient_id = ?" . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM notifications WHERE {$where}", [$staffId]);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = $this->db->select("SELECT * FROM notifications WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), [$staffId]);
        return ['rows' => array_map(fn (array $n) => $this->decorate($n), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array<string, mixed>|null the staff user's own notification */
    public function find(int $id, int $staffId): ?array
    {
        $n = $this->db->first("SELECT * FROM notifications WHERE id = ? AND recipient_type = 'staff' AND recipient_id = ?", [$id, $staffId]);
        return $n !== null ? $this->decorate($n) : null;
    }

    public function markRead(int $id, int $staffId): bool
    {
        return $this->db->execute("UPDATE notifications SET read_at = ? WHERE id = ? AND recipient_type = 'staff' AND recipient_id = ? AND read_at IS NULL", [$this->clock->sql(), $id, $staffId]) > 0;
    }

    public function markAllRead(int $staffId): int
    {
        return $this->db->execute("UPDATE notifications SET read_at = ? WHERE recipient_type = 'staff' AND recipient_id = ? AND read_at IS NULL", [$this->clock->sql(), $staffId]);
    }

    /**
     * Adds `url` (where the notification points) and `icon`.
     *
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private function decorate(array $n): array
    {
        $data = json_decode((string) ($n['data'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $url = match (true) {
            isset($data['url']) && is_string($data['url']) && str_starts_with($data['url'], '/staff/') => $data['url'],
            isset($data['booking_no']) => url('staff.bookings.show', ['no' => (string) $data['booking_no']]),
            isset($data['import_id']) => url('staff.imports.show', ['id' => (int) $data['import_id']]),
            default => null,
        };
        $icon = match (explode('.', (string) $n['type'])[0]) {
            'payment' => 'wallet',
            'renewal' => 'refresh-cw',
            'kyc' => 'shield-check',
            'import' => 'file-spreadsheet',
            default => 'calendar-check',
        };
        return $n + ['url' => $url, 'icon' => $icon];
    }
}
