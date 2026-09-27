-- GST invoices, items, receipts, credit notes and gap-free number sequences.
-- Numbers are generated under a row lock on number_sequences (SELECT ... FOR UPDATE).
-- Financial documents are never hard-deleted (ON DELETE RESTRICT everywhere).

CREATE TABLE number_sequences (
    name          VARCHAR(30) NOT NULL COMMENT 'invoice | receipt | credit_note | booking | visitor_I | visitor_N',
    period        VARCHAR(10) NOT NULL COMMENT 'FY like 2026-27 or calendar year 2026',
    last_value    INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_no          VARCHAR(40) NOT NULL COMMENT 'KDISC/CMN/{2026-27}/{0001}',
    fy                  VARCHAR(7)  NOT NULL,
    seq                 INT UNSIGNED NOT NULL,
    centre_id           BIGINT UNSIGNED NOT NULL,
    customer_id         BIGINT UNSIGNED NOT NULL,
    booking_id          BIGINT UNSIGNED NULL,
    payment_id          BIGINT UNSIGNED NULL,
    invoice_date        DATE NOT NULL,
    customer_name       VARCHAR(190) NOT NULL COMMENT 'snapshot at issue time',
    customer_address    VARCHAR(500) NULL,
    customer_gstin      CHAR(15) NULL,
    place_of_supply     CHAR(2) NOT NULL COMMENT 'state code',
    taxable_value       DECIMAL(12,2) NOT NULL DEFAULT 0,
    cgst                DECIMAL(12,2) NOT NULL DEFAULT 0,
    sgst                DECIMAL(12,2) NOT NULL DEFAULT 0,
    igst                DECIMAL(12,2) NOT NULL DEFAULT 0,
    round_off           DECIMAL(6,2)  NOT NULL DEFAULT 0,
    total               DECIMAL(12,2) NOT NULL DEFAULT 0,
    status              VARCHAR(12) NOT NULL DEFAULT 'issued' COMMENT 'issued | cancelled',
    pdf_path            VARCHAR(255) NULL COMMENT 'relative to storage/pdf',
    print_count         INT UNSIGNED NOT NULL DEFAULT 0,
    issued_by           BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_no (invoice_no),
    UNIQUE KEY uq_invoices_fy_seq (fy, seq),
    KEY idx_invoices_customer (customer_id),
    KEY idx_invoices_date (invoice_date),
    CONSTRAINT fk_invoices_centre FOREIGN KEY (centre_id) REFERENCES centres (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_issued_by FOREIGN KEY (issued_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    description     VARCHAR(255) NOT NULL,
    sac             VARCHAR(10)  NOT NULL DEFAULT '997212',
    qty             DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit            VARCHAR(10)  NULL,
    rate            DECIMAL(12,2) NOT NULL,
    taxable_value   DECIMAL(12,2) NOT NULL,
    gst_rate        DECIMAL(5,2)  NOT NULL,
    cgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    sgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    igst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL,
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    receipt_no    VARCHAR(40) NOT NULL COMMENT 'RCPT/{FY}/{0001}',
    fy            VARCHAR(7)  NOT NULL,
    seq           INT UNSIGNED NOT NULL,
    payment_id    BIGINT UNSIGNED NOT NULL,
    customer_id   BIGINT UNSIGNED NOT NULL,
    invoice_id    BIGINT UNSIGNED NULL,
    kind          VARCHAR(10) NOT NULL COMMENT 'payment | deposit',
    amount        DECIMAL(12,2) NOT NULL,
    receipt_date  DATE NOT NULL,
    pdf_path      VARCHAR(255) NULL,
    print_count   INT UNSIGNED NOT NULL DEFAULT 0,
    issued_by     BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_no (receipt_no),
    UNIQUE KEY uq_receipts_fy_seq (fy, seq),
    KEY idx_receipts_customer (customer_id),
    CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_receipts_issued_by FOREIGN KEY (issued_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credit_note_no  VARCHAR(40) NOT NULL,
    fy              VARCHAR(7)  NOT NULL,
    seq             INT UNSIGNED NOT NULL,
    invoice_id      BIGINT UNSIGNED NOT NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    note_date       DATE NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    taxable_value   DECIMAL(12,2) NOT NULL DEFAULT 0,
    cgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    sgst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    igst            DECIMAL(12,2) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0,
    pdf_path        VARCHAR(255) NULL,
    issued_by       BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_notes_no (credit_note_no),
    UNIQUE KEY uq_credit_notes_fy_seq (fy, seq),
    CONSTRAINT fk_credit_notes_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_notes_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_credit_notes_issued_by FOREIGN KEY (issued_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
