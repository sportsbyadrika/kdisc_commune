<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Exceptions\ValidationException;
use App\Services\AuditLog;
use App\Services\Kyc\DocumentStore;
use App\Services\SettingsService;
use App\Support\IndianStates;

/**
 * Supplier + document settings for GST invoices, receipts and credit notes (/staff/finance/settings), stored in
 * the `settings` table (group finance / tax / numbering). supplier() is the snapshot copied into every issued
 * document (invoices.supplier_json …) so later edits never change a document that was already issued.
 * Logo / signature / seal images go through the secure upload pipeline into storage/uploads/finance/.
 */
final class FinanceSettings
{
    /** key => [label, rules, group hint] — the editable text fields */
    public const FIELDS = [
        'supplier_legal_name' => ['Legal name', 'required|string|max:190'],
        'supplier_trade_name' => ['Trade name / centre', 'nullable|string|max:190'],
        'supplier_address' => ['Registered address', 'required|string|max:500'],
        'org_gstin' => ['GSTIN', 'nullable|gstin'],
        'supplier_pan' => ['PAN', 'nullable|pan'],
        'home_state_code' => ['State code', 'required|string|size:2'],
        'supplier_email' => ['Accounts email', 'nullable|email|max:190'],
        'supplier_phone' => ['Accounts phone', 'nullable|string|max:40'],
        'sac_code' => ['SAC code', 'required|regex:/^\d{4,8}$/'],
        'gst_rate' => ['Default GST rate (%)', 'required|numeric|min:0|max:28'],
        'invoice_prefix' => ['Invoice prefix', 'required|regex:/^[A-Za-z0-9\/-]{1,20}$/'],
        'receipt_prefix' => ['Receipt prefix', 'required|regex:/^[A-Za-z0-9\/-]{1,20}$/'],
        'credit_note_prefix' => ['Credit note prefix', 'required|regex:/^[A-Za-z0-9\/-]{1,20}$/'],
        'refund_voucher_prefix' => ['Deposit refund voucher prefix', 'required|regex:/^[A-Za-z0-9\/-]{1,20}$/'],
        'bank_account_name' => ['Account name', 'nullable|string|max:190'],
        'bank_name' => ['Bank', 'nullable|string|max:120'],
        'bank_branch' => ['Branch', 'nullable|string|max:120'],
        'bank_account_no' => ['Account number', 'nullable|regex:/^[0-9A-Za-z]{6,20}$/'],
        'bank_ifsc' => ['IFSC', 'nullable|regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
        'bank_upi' => ['UPI ID', 'nullable|regex:/^[A-Za-z0-9._-]{2,64}@[A-Za-z]{2,64}$/'],
        'signatory_name' => ['Authorised signatory', 'nullable|string|max:120'],
        'signatory_designation' => ['Designation', 'nullable|string|max:120'],
        'invoice_terms' => ['Terms on invoices', 'nullable|string|max:1500'],
        'invoice_footer' => ['Footer line', 'nullable|string|max:300'],
    ];

    public const IMAGES = [
        'finance_logo_path' => 'Logo',
        'finance_signature_path' => 'Signature',
        'finance_seal_path' => 'Seal / stamp',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DocumentStore $files,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array<string, mixed> current values for the form */
    public function values(): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS + self::IMAGES) as $key) {
            $out[$key] = (string) $this->settings->get($key, '');
        }
        $out['finance_email_documents'] = (bool) $this->settings->get('finance_email_documents', true);
        return $out;
    }

    /** @return array<string, string|list<string>> */
    public static function rules(): array
    {
        $rules = array_map(static fn (array $f) => $f[1], self::FIELDS);
        $rules['home_state_code'] = ['required', IndianStates::rule()];
        return $rules;
    }

    /**
     * @param array<string, mixed> $data validated input
     * @param array<string, array<string, mixed>|null> $uploads $_FILES entries keyed like IMAGES
     * @param list<string> $remove image keys to clear
     */
    public function save(array $data, array $uploads = [], array $remove = [], bool $trusted = false): void
    {
        $before = $this->values();
        foreach (array_keys(self::FIELDS) as $key) {
            $v = trim((string) ($data[$key] ?? ''));
            if (in_array($key, ['org_gstin', 'supplier_pan', 'bank_ifsc'], true)) {
                $v = strtoupper($v);
            }
            $this->settings->set($key, $v);
        }
        $gst = (float) ($data['gst_rate'] ?? 18);
        $this->settings->set('cgst_rate', round($gst / 2, 2));
        $this->settings->set('sgst_rate', round($gst / 2, 2));
        $this->settings->set('igst_rate', $gst);
        $this->settings->set('finance_email_documents', !empty($data['finance_email_documents']));
        foreach (self::IMAGES as $key => $label) {
            $upload = $uploads[$key] ?? null;
            if (in_array($key, $remove, true)) {
                $this->settings->set($key, '');
            }
            if ($upload === null || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $stored = $this->files->storeFile($upload, 'finance', $key, $trusted);
            if (!str_starts_with($stored['mime'], 'image/')) {
                throw new ValidationException([$key => [$label . ' must be a PNG, JPG or WebP image.']]);
            }
            $this->settings->set($key, $stored['path']);
        }
        $after = $this->values();
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        if ($changed !== []) {
            $this->audit->record('finance.settings', 'settings', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        }
    }

    /**
     * Supplier snapshot stored on each issued document and printed in its header.
     *
     * @return array<string, string>
     */
    public function supplier(): array
    {
        $s = fn (string $k, string $d = '') => trim((string) $this->settings->get($k, $d));
        $state = $s('home_state_code', '32');
        return [
            'legal_name' => $s('supplier_legal_name', (string) config('app.org.name', 'K-DISC')),
            'trade_name' => $s('supplier_trade_name'),
            'address' => $s('supplier_address', (string) config('app.org.address', '')),
            'gstin' => $s('org_gstin'),
            'pan' => $s('supplier_pan'),
            'state_code' => $state,
            'state' => IndianStates::name($state),
            'email' => $s('supplier_email'),
            'phone' => $s('supplier_phone'),
            'sac' => $s('sac_code', '997212'),
            'bank_account_name' => $s('bank_account_name'),
            'bank_name' => $s('bank_name'),
            'bank_branch' => $s('bank_branch'),
            'bank_account_no' => $s('bank_account_no'),
            'bank_ifsc' => $s('bank_ifsc'),
            'bank_upi' => $s('bank_upi'),
            'signatory_name' => $s('signatory_name'),
            'signatory_designation' => $s('signatory_designation'),
            'terms' => $s('invoice_terms'),
            'footer' => $s('invoice_footer'),
            'logo_path' => $s('finance_logo_path'),
            'signature_path' => $s('finance_signature_path'),
            'seal_path' => $s('finance_seal_path'),
        ];
    }

    public function homeState(): string
    {
        return (string) ($this->settings->get('home_state_code', '32') ?: '32');
    }

    public function sac(): string
    {
        return (string) ($this->settings->get('sac_code', '997212') ?: '997212');
    }

    public function emailDocuments(): bool
    {
        return (bool) $this->settings->get('finance_email_documents', true);
    }

    /** Absolute path of an uploaded finance image, or null. */
    public function imagePath(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }
        try {
            return $this->files->path(['file_path' => $relative]);
        } catch (\Throwable) {
            return null;
        }
    }
}
