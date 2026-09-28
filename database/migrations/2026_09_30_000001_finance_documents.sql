-- Batch 6 — Finance: payment verification, GST invoices, receipts, credit notes, deposit refunds (spec 6.4, 7.3, 9).
--
--   payments          Finance "query" round-trip with the front desk (flag + note + reply)
--   invoices          kind advance | rent, source_key (one invoice per booking advance / per rent period — unique),
--                     snapshots of the recipient + supplier at issue time (immutable documents), amount in words,
--                     credited_total (sum of credit notes)
--   invoice_items     kind seat | addon, detail line
--   receipts          one per verified payment (unique payment_id), mode/reference snapshot
--   credit_notes      reason_code, GST reversal lines in credit_note_items
--   deposit_refunds   deposit refund voucher (one per booking) with adjustments
-- Numbers: number_sequences rows invoice / receipt / credit_note / refund_voucher, period = Indian FY (2026-27).

ALTER TABLE payments
    ADD COLUMN query_note        VARCHAR(500) NULL COMMENT 'Finance question to the front desk' AFTER voided_at,
    ADD COLUMN queried_by        BIGINT UNSIGNED NULL AFTER query_note,
    ADD COLUMN queried_at        DATETIME NULL AFTER queried_by,
    ADD COLUMN query_reply       VARCHAR(500) NULL AFTER queried_at,
    ADD COLUMN query_resolved_by BIGINT UNSIGNED NULL AFTER query_reply,
    ADD COLUMN query_resolved_at DATETIME NULL AFTER query_resolved_by,
    ADD KEY idx_payments_query (queried_at, query_resolved_at),
    ADD CONSTRAINT fk_payments_queried_by FOREIGN KEY (queried_by) REFERENCES staff_users (id) ON DELETE SET NULL;

ALTER TABLE invoices
    ADD COLUMN kind                VARCHAR(10) NOT NULL DEFAULT 'advance' COMMENT 'advance | rent' AFTER seq,
    ADD COLUMN source_key          VARCHAR(20) NOT NULL DEFAULT 'advance' COMMENT 'advance | rent:{period_no}' AFTER kind,
    ADD COLUMN rent_schedule_id    BIGINT UNSIGNED NULL AFTER payment_id,
    ADD COLUMN period_start        DATE NULL AFTER invoice_date,
    ADD COLUMN period_end          DATE NULL AFTER period_start,
    ADD COLUMN booking_no          VARCHAR(20) NULL AFTER period_end,
    ADD COLUMN customer_unique_id  VARCHAR(30) NULL AFTER customer_name,
    ADD COLUMN customer_email      VARCHAR(190) NULL AFTER customer_unique_id,
    ADD COLUMN customer_pan        VARCHAR(10) NULL AFTER customer_gstin,
    ADD COLUMN customer_state_code CHAR(2) NULL AFTER customer_pan,
    ADD COLUMN supplier_json       TEXT NULL COMMENT 'supplier details at issue time' AFTER total,
    ADD COLUMN amount_words        VARCHAR(300) NULL AFTER supplier_json,
    ADD COLUMN credited_total      DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_words,
    ADD COLUMN emailed_at          DATETIME NULL AFTER print_count,
    ADD UNIQUE KEY uq_invoices_source (booking_id, source_key),
    ADD KEY idx_invoices_fy (fy, invoice_date),
    ADD CONSTRAINT fk_invoices_rent_schedule FOREIGN KEY (rent_schedule_id) REFERENCES rent_schedules (id) ON DELETE RESTRICT;

ALTER TABLE invoice_items
    ADD COLUMN kind   VARCHAR(10) NOT NULL DEFAULT 'seat' COMMENT 'seat | addon' AFTER invoice_id,
    ADD COLUMN detail VARCHAR(255) NULL AFTER description;

ALTER TABLE receipts
    ADD COLUMN booking_id    BIGINT UNSIGNED NULL AFTER payment_id,
    ADD COLUMN customer_name VARCHAR(190) NULL AFTER customer_id,
    ADD COLUMN mode          VARCHAR(20) NULL AFTER amount,
    ADD COLUMN reference_no  VARCHAR(100) NULL AFTER mode,
    ADD COLUMN paid_on       DATE NULL AFTER reference_no,
    ADD COLUMN amount_words  VARCHAR(300) NULL AFTER paid_on,
    ADD COLUMN supplier_json TEXT NULL AFTER amount_words,
    ADD COLUMN emailed_at    DATETIME NULL AFTER print_count,
    ADD UNIQUE KEY uq_receipts_payment (payment_id),
    ADD KEY idx_receipts_fy (fy, receipt_date),
    ADD CONSTRAINT fk_receipts_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT;

ALTER TABLE credit_notes
    ADD COLUMN booking_id      BIGINT UNSIGNED NULL AFTER invoice_id,
    ADD COLUMN reason_code     VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'App\\Enums\\CreditNoteReason' AFTER note_date,
    ADD COLUMN customer_name   VARCHAR(190) NULL AFTER customer_id,
    ADD COLUMN place_of_supply CHAR(2) NULL AFTER reason,
    ADD COLUMN round_off       DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER igst,
    ADD COLUMN amount_words    VARCHAR(300) NULL AFTER total,
    ADD COLUMN supplier_json   TEXT NULL AFTER amount_words,
    ADD COLUMN print_count     INT UNSIGNED NOT NULL DEFAULT 0 AFTER pdf_path,
    ADD COLUMN emailed_at      DATETIME NULL AFTER print_count,
    ADD KEY idx_credit_notes_fy (fy, note_date),
    ADD KEY idx_credit_notes_invoice (invoice_id),
    ADD CONSTRAINT fk_credit_notes_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT;

CREATE TABLE credit_note_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credit_note_id  BIGINT UNSIGNED NOT NULL,
    invoice_item_id BIGINT UNSIGNED NULL,
    description     VARCHAR(255) NOT NULL,
    sac             VARCHAR(10)  NOT NULL,
    taxable_value   DECIMAL(12,2) NOT NULL,
    gst_rate        DECIMAL(5,2)  NOT NULL,
    cgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    sgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    igst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_credit_note_items_note (credit_note_id),
    CONSTRAINT fk_credit_note_items_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_note_items_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deposit_refunds (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    voucher_no        VARCHAR(40) NOT NULL COMMENT 'DRV/{FY}/{0001}',
    fy                VARCHAR(7)  NOT NULL,
    seq               INT UNSIGNED NOT NULL,
    booking_id        BIGINT UNSIGNED NOT NULL,
    customer_id       BIGINT UNSIGNED NOT NULL,
    customer_name     VARCHAR(190) NOT NULL,
    voucher_date      DATE NOT NULL,
    deposit_held      DECIMAL(12,2) NOT NULL,
    adjustments_json  TEXT NULL COMMENT '[{label, amount}]',
    adjustments_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    refund_amount     DECIMAL(12,2) NOT NULL,
    mode              VARCHAR(20) NULL,
    reference_no      VARCHAR(100) NULL,
    notes             VARCHAR(500) NULL,
    amount_words      VARCHAR(300) NULL,
    supplier_json     TEXT NULL,
    pdf_path          VARCHAR(255) NULL,
    print_count       INT UNSIGNED NOT NULL DEFAULT 0,
    issued_by         BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deposit_refunds_no (voucher_no),
    UNIQUE KEY uq_deposit_refunds_fy_seq (fy, seq),
    UNIQUE KEY uq_deposit_refunds_booking (booking_id),
    KEY idx_deposit_refunds_customer (customer_id),
    CONSTRAINT fk_deposit_refunds_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
    CONSTRAINT fk_deposit_refunds_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_deposit_refunds_issued_by FOREIGN KEY (issued_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE settings SET `value` = 'CN' WHERE `key` = 'credit_note_prefix' AND `value` = 'KDISC/CN';

INSERT IGNORE INTO settings (`key`, `value`, `type`, `group`, label) VALUES
    ('supplier_legal_name', 'Kerala Development and Innovation Strategic Council (K-DISC)', 'string', 'finance', 'Supplier legal name'),
    ('supplier_trade_name', 'Commune Workspace, Kottarakara', 'string', 'finance', 'Trade name / centre'),
    ('supplier_address', 'Commune Workspace, Kottarakara, Kollam, Kerala 691506', 'string', 'finance', 'Supplier address'),
    ('supplier_pan', '', 'string', 'finance', 'Supplier PAN'),
    ('supplier_email', 'commune.ktr@kdisc.kerala.gov.in', 'string', 'finance', 'Accounts email'),
    ('supplier_phone', '+91 474 000 0000', 'string', 'finance', 'Accounts phone'),
    ('refund_voucher_prefix', 'DRV', 'string', 'numbering', 'Deposit refund voucher prefix → DRV/2026-27/0001'),
    ('bank_account_name', 'K-DISC Commune Kottarakara', 'string', 'finance', 'Bank account name'),
    ('bank_name', '', 'string', 'finance', 'Bank'),
    ('bank_branch', '', 'string', 'finance', 'Branch'),
    ('bank_account_no', '', 'string', 'finance', 'Account number'),
    ('bank_ifsc', '', 'string', 'finance', 'IFSC'),
    ('bank_upi', '', 'string', 'finance', 'UPI ID'),
    ('signatory_name', '', 'string', 'finance', 'Authorised signatory'),
    ('signatory_designation', 'Finance Officer', 'string', 'finance', 'Signatory designation'),
    ('finance_logo_path', '', 'string', 'finance', 'Logo image (uploads/finance)'),
    ('finance_signature_path', '', 'string', 'finance', 'Signature image (uploads/finance)'),
    ('finance_seal_path', '', 'string', 'finance', 'Seal image (uploads/finance)'),
    ('invoice_terms', 'Payment is due on or before the due date shown on your booking. Security deposits are refundable at the end of the tenure after adjustments. Subject to Kollam jurisdiction.', 'string', 'finance', 'Terms printed on invoices'),
    ('invoice_footer', 'This is a computer-generated document.', 'string', 'finance', 'Footer line on finance documents'),
    ('finance_email_documents', '1', 'bool', 'finance', 'Email invoices and receipts to the visitor when issued');
