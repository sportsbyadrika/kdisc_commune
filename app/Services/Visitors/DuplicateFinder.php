<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\IdValidator;

/**
 * "Possible existing visitor" check (spec 4.2): the same person or institution must not be registered
 * twice (online and at the desk). Matches on email, mobile, Aadhaar hash (visitor or signatory), PAN, GSTIN.
 *
 *   $matches = $finder->find(['email' => 'a@b.in', 'mobile' => '+919876543210', 'aadhaar_hash' => $hash]);
 *   // [['customer' => [...safe row...], 'fields' => ['email', 'aadhaar']], ...]
 */
final class DuplicateFinder
{
    private const LABELS = ['email' => 'Email', 'mobile' => 'Mobile', 'aadhaar' => 'Aadhaar', 'pan' => 'PAN', 'gstin' => 'GSTIN'];

    public function __construct(private readonly Database $db, private readonly AadhaarVault $vault)
    {
    }

    /**
     * Build criteria from raw form input (normalises values, hashes Aadhaar numbers).
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function criteriaFromInput(array $input): array
    {
        $c = [];
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $c['email'] = $email;
        }
        $mobile = IdValidator::normalizeMobile((string) ($input['mobile'] ?? ''));
        if ($mobile !== null) {
            $c['mobile'] = $mobile;
        }
        foreach (['aadhaar', 'sig_aadhaar'] as $k) {
            $a = (string) ($input[$k] ?? '');
            if (IdValidator::aadhaar($a)) {
                $c['aadhaar_hash'] = $this->vault->hash($a);
            }
        }
        $pan = IdValidator::normalize((string) ($input['pan'] ?? ''));
        if (IdValidator::pan($pan)) {
            $c['pan'] = $pan;
        }
        $gstin = IdValidator::normalize((string) ($input['gstin'] ?? ''));
        if (IdValidator::gstin($gstin)) {
            $c['gstin'] = $gstin;
        }
        return $c;
    }

    /**
     * @param array<string, string> $criteria keys: email, mobile, aadhaar_hash, pan, gstin
     * @return list<array{customer: array<string, mixed>, fields: list<string>, labels: list<string>}>
     */
    public function find(array $criteria, ?int $excludeId = null, int $limit = 5): array
    {
        $where = [];
        $bindings = [];
        foreach (['email', 'mobile', 'aadhaar_hash', 'pan', 'gstin'] as $col) {
            if (($criteria[$col] ?? '') !== '') {
                $where[] = "c.{$col} = ?";
                $bindings[] = $criteria[$col];
            }
        }
        if (($criteria['aadhaar_hash'] ?? '') !== '') {
            $where[] = 'c.id IN (SELECT customer_id FROM customer_signatories WHERE aadhaar_hash = ?)';
            $bindings[] = $criteria['aadhaar_hash'];
        }
        if ($where === []) {
            return [];
        }
        $sql = 'SELECT c.id, c.unique_id, c.type, c.name, c.email, c.mobile, c.pan, c.gstin, c.aadhaar_hash, c.aadhaar_last4, c.kyc_status, c.created_at,
                       (SELECT s.aadhaar_hash FROM customer_signatories s WHERE s.customer_id = c.id AND s.aadhaar_hash = ? LIMIT 1) AS sig_hash
                FROM customers c WHERE (' . implode(' OR ', $where) . ')';
        array_unshift($bindings, (string) ($criteria['aadhaar_hash'] ?? ''));
        if ($excludeId !== null) {
            $sql .= ' AND c.id <> ?';
            $bindings[] = $excludeId;
        }
        $sql .= ' ORDER BY c.id DESC LIMIT ' . max(1, $limit);

        $out = [];
        foreach ($this->db->select($sql, $bindings) as $row) {
            $fields = [];
            foreach (['email', 'mobile', 'pan', 'gstin'] as $col) {
                if (($criteria[$col] ?? '') !== '' && (string) $row[$col] === $criteria[$col]) {
                    $fields[] = $col;
                }
            }
            if (($criteria['aadhaar_hash'] ?? '') !== '' && ($row['aadhaar_hash'] === $criteria['aadhaar_hash'] || $row['sig_hash'] !== null)) {
                $fields[] = 'aadhaar';
            }
            unset($row['aadhaar_hash'], $row['sig_hash']);
            $out[] = ['customer' => $row, 'fields' => $fields, 'labels' => array_map(static fn ($f) => self::LABELS[$f], $fields)];
        }
        return $out;
    }
}
