<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Enums\CustomerType;
use App\Enums\KycStatus;
use App\Services\Kyc\IdValidator;

/**
 * Read-side queries for the staff console: visitor search/list and the KYC queue.
 * Rows never include aadhaar_enc / aadhaar_hash.
 */
final class VisitorDirectory
{
    private const COLUMNS = 'c.id, c.unique_id, c.type, c.sub_category, c.name, c.email, c.mobile, c.pan, c.gstin, c.nationality,
        c.aadhaar_last4, c.passport_no, c.kyc_status, c.kyc_submitted_at, c.kyc_verified_at, c.registered_via, c.profile_step, c.account_id, c.created_at';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{q?: string, type?: string, kyc?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $parts = ['c.unique_id LIKE ?', 'c.name LIKE ?', 'c.email LIKE ?', 'c.pan = ?', 'c.gstin = ?'];
            array_push($bindings, $like, $like, $like, IdValidator::normalize($q), IdValidator::normalize($q));
            $digits = (string) preg_replace('/\D+/', '', $q);
            if (strlen($digits) >= 4) {
                $parts[] = 'c.mobile LIKE ?';
                $bindings[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (CustomerType::tryFrom((string) ($filters['type'] ?? '')) !== null) {
            $where[] = 'c.type = ?';
            $bindings[] = $filters['type'];
        }
        if (KycStatus::tryFrom((string) ($filters['kyc'] ?? '')) !== null) {
            $where[] = 'c.kyc_status = ?';
            $bindings[] = $filters['kyc'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM customers c WHERE {$whereSql}", $bindings);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM customers c WHERE {$whereSql} ORDER BY c.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bindings,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array<string, int> kyc_status => count */
    public function kycCounts(): array
    {
        $out = array_fill_keys(KycStatus::values(), 0);
        foreach ($this->db->select('SELECT kyc_status, COUNT(*) AS n FROM customers GROUP BY kyc_status') as $row) {
            $out[(string) $row['kyc_status']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * KYC queue, oldest submission first.
     * @return list<array<string, mixed>>
     */
    public function queue(KycStatus $status = KycStatus::Pending, int $limit = 100): array
    {
        $order = $status === KycStatus::Pending ? 'c.kyc_submitted_at ASC' : 'c.updated_at DESC';
        return $this->db->select(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM customer_documents d WHERE d.customer_id = c.id) AS documents
             FROM customers c WHERE c.kyc_status = ? ORDER BY ' . $order . ' LIMIT ' . max(1, $limit),
            [$status->value],
        );
    }
}
