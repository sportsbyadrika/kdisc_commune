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
            ['credit_note_prefix', 'CN', 'string', 'numbering', 'Credit note prefix → CN/2026-27/0001'],
            ['refund_voucher_prefix', 'DRV', 'string', 'numbering', 'Deposit refund voucher prefix → DRV/2026-27/0001'],
            ['booking_prefix', 'BK', 'string', 'numbering', 'Booking number prefix → BK-2026-000001'],
            ['visitor_id_prefix', 'CMN-KTR', 'string', 'numbering', 'Unique visitor ID prefix → CMN-KTR-I-2026-00001'],
            ['seat_hold_minutes', '10', 'int', 'booking', 'Seat hold duration while choosing (minutes)'],
            ['advance_max_months', '6', 'int', 'booking', 'Tenures up to this many months need an advance; longer need a security deposit'],
            ['security_deposit_months', '2', 'int', 'booking', 'Security deposit = N months of rent (to be confirmed)'],
            ['availability_poll_seconds', '20', 'int', 'booking', 'Space Explorer availability refresh interval (seconds)'],
            ['flexi_pricing_rule', 'monthly_plus_daily', 'string', 'booking', 'Flexi pricing: under a month = daily rate x days; a month or more = monthly rate x months + remaining days at the daily rate'],
            ['flexi_daily_cap_monthly', '1', 'bool', 'booking', 'Cap the daily-rate part of a flexi booking at one month\'s rate'],
            ['proration_days_per_month', '30', 'int', 'booking', 'Partial months of monthly-billed seats are pro-rated as days / N'],
            ['conference_open_hour', '8', 'int', 'booking', 'Conference room bookable from (hour, 24h)'],
            ['conference_close_hour', '20', 'int', 'booking', 'Conference room bookable until (hour, 24h)'],
            ['max_seats_per_booking', '40', 'int', 'booking', 'Maximum seats in one booking request'],
            ['max_booking_months', '36', 'int', 'booking', 'Longest tenure that can be requested online (months)'],
            ['approval_payment_days', '7', 'int', 'booking', 'Approved bookings expire when the required payment is not logged within N days'],
            ['renewal_reminder_days', '15,7,1', 'string', 'booking', 'Renewal reminders are emailed this many days before the end date'],
            ['checkin_open_hour', '7', 'int', 'booking', 'Earliest hour for check-in on the start date (informational)'],
            // Finance documents (batch 6) — edited at /staff/finance/settings (FinanceSettings).
            ['supplier_legal_name', 'Kerala Development and Innovation Strategic Council (K-DISC)', 'string', 'finance', 'Supplier legal name'],
            ['supplier_trade_name', 'Commune Workspace, Kottarakara', 'string', 'finance', 'Trade name / centre'],
            ['supplier_address', 'Commune Workspace, Kottarakara, Kollam, Kerala 691506', 'string', 'finance', 'Supplier address'],
            ['supplier_pan', '', 'string', 'finance', 'Supplier PAN'],
            ['supplier_email', 'commune.ktr@kdisc.kerala.gov.in', 'string', 'finance', 'Accounts email'],
            ['supplier_phone', '+91 474 000 0000', 'string', 'finance', 'Accounts phone'],
            ['bank_account_name', 'K-DISC Commune Kottarakara', 'string', 'finance', 'Bank account name'],
            ['bank_name', '', 'string', 'finance', 'Bank'],
            ['bank_branch', '', 'string', 'finance', 'Branch'],
            ['bank_account_no', '', 'string', 'finance', 'Account number'],
            ['bank_ifsc', '', 'string', 'finance', 'IFSC'],
            ['bank_upi', '', 'string', 'finance', 'UPI ID'],
            ['signatory_name', '', 'string', 'finance', 'Authorised signatory'],
            ['signatory_designation', 'Finance Officer', 'string', 'finance', 'Signatory designation'],
            ['finance_logo_path', '', 'string', 'finance', 'Logo image (uploads/finance)'],
            ['finance_signature_path', '', 'string', 'finance', 'Signature image (uploads/finance)'],
            ['finance_seal_path', '', 'string', 'finance', 'Seal image (uploads/finance)'],
            ['invoice_terms', 'Payment is due on or before the due date shown on your booking. Security deposits are refundable at the end of the tenure after adjustments. Subject to Kollam jurisdiction.', 'string', 'finance', 'Terms printed on invoices'],
            ['invoice_footer', 'This is a computer-generated document.', 'string', 'finance', 'Footer line on finance documents'],
            ['finance_email_documents', '1', 'bool', 'finance', 'Email invoices and receipts to the visitor when issued'],
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
