-- Batch 7 — dashboards, reports & XLSX bulk import (spec 6.4, 6.5, 10).
--
--   import_batches  one row per uploaded workbook: type, who, totals, mode (per_row | all_or_nothing), the secure
--                   temp token (the parsed file lives only in storage/imports/tmp/{token}.xlsx until confirm / expiry
--                   and is then deleted — full Aadhaar numbers never outlive that window), expiry, per-batch summary
--                   and the error-report path (Aadhaar masked).
--   notifications   index for the staff inbox (newest first per recipient).

ALTER TABLE import_batches
    MODIFY status VARCHAR(12) NOT NULL DEFAULT 'uploaded' COMMENT 'uploaded | validated | imported | partial | failed | expired | discarded',
    MODIFY file_path VARCHAR(255) NULL COMMENT 'temp workbook (relative to storage/imports) — cleared when deleted',
    ADD COLUMN token          CHAR(32) NULL COMMENT 'random name of the temp workbook + masked preview' AFTER file_path,
    ADD COLUMN file_size      INT UNSIGNED NOT NULL DEFAULT 0 AFTER token,
    ADD COLUMN mode           VARCHAR(16) NOT NULL DEFAULT 'per_row' COMMENT 'per_row | all_or_nothing' AFTER status,
    ADD COLUMN imported_rows  INT UNSIGNED NOT NULL DEFAULT 0 AFTER error_rows,
    ADD COLUMN failed_rows    INT UNSIGNED NOT NULL DEFAULT 0 AFTER imported_rows,
    ADD COLUMN invites_sent   INT UNSIGNED NOT NULL DEFAULT 0 AFTER send_invites,
    ADD COLUMN summary        JSON NULL COMMENT 'created references, failures per row' AFTER invites_sent,
    ADD COLUMN expires_at     DATETIME NULL COMMENT 'temp files deleted after this' AFTER summary,
    ADD COLUMN imported_by    BIGINT UNSIGNED NULL AFTER imported_at,
    ADD UNIQUE KEY uq_import_batches_token (token),
    ADD KEY idx_import_batches_created (created_at),
    ADD CONSTRAINT fk_import_batches_imported_by FOREIGN KEY (imported_by) REFERENCES staff_users (id) ON DELETE SET NULL;

ALTER TABLE notifications
    ADD KEY idx_notifications_inbox (recipient_type, recipient_id, id);

INSERT IGNORE INTO settings (`key`, `value`, `type`, `group`, label) VALUES
    ('import_max_rows', '1000', 'int', 'imports', 'Maximum data rows in one bulk-upload workbook'),
    ('import_max_mb', '5', 'int', 'imports', 'Maximum bulk-upload workbook size (MB)'),
    ('import_expiry_minutes', '120', 'int', 'imports', 'Uploaded workbooks not confirmed within N minutes are deleted');
