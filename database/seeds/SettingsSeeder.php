<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;

/** Business settings (spec 7.2 / 7.3). Read with setting('key'). Most items still need K-DISC confirmation. */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // key, value, type, group, label
            ['gst_rate', '18', 'float', 'tax', 'Default GST rate (%)'],
            ['cgst_rate', '9', 'float', 'tax', 'CGST rate (%) — intra-state'],
            ['sgst_rate', '9', 'float', 'tax', 'SGST rate (%) — intra-state'],
            ['igst_rate', '18', 'float', 'tax', 'IGST rate (%) — inter-state'],
            ['home_state_code', '32', 'string', 'tax', 'Supplier GST state code (Kerala)'],
            ['sac_code', '997212', 'string', 'tax', 'SAC code (to be confirmed by Finance)'],
            ['org_gstin', '', 'string', 'tax', 'K-DISC GSTIN (pending)'],
            ['invoice_prefix', 'KDISC/CMN', 'string', 'numbering', 'Invoice number prefix → KDISC/CMN/2026-27/0001'],
            ['receipt_prefix', 'RCPT', 'string', 'numbering', 'Receipt number prefix → RCPT/2026-27/0001'],
            ['credit_note_prefix', 'KDISC/CN', 'string', 'numbering', 'Credit note prefix'],
            ['booking_prefix', 'BK', 'string', 'numbering', 'Booking number prefix → BK-2026-000001'],
            ['visitor_id_prefix', 'CMN-KTR', 'string', 'numbering', 'Unique visitor ID prefix → CMN-KTR-I-2026-00001'],
            ['seat_hold_minutes', '10', 'int', 'booking', 'Seat hold duration while choosing (minutes)'],
            ['advance_max_months', '6', 'int', 'booking', 'Tenures up to this many months need an advance; longer need a security deposit'],
            ['security_deposit_months', '1', 'int', 'booking', 'Security deposit = N months of rent (to be confirmed)'],
            ['availability_poll_seconds', '25', 'int', 'booking', 'Space Explorer availability refresh interval'],
            ['password_token_minutes', '60', 'int', 'auth', 'Set/reset password link validity (minutes)'],
        ];
        foreach ($settings as [$key, $value, $type, $group, $label]) {
            $this->db->execute(
                'INSERT INTO settings (`key`, `value`, `type`, `group`, label) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE `type` = VALUES(`type`), `group` = VALUES(`group`), label = VALUES(label)',
                [$key, $value, $type, $group, $label],
            );
        }
    }
}
