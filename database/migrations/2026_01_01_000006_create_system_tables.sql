-- Bulk imports, audit log, notifications.

CREATE TABLE import_batches (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type               VARCHAR(30)  NOT NULL COMMENT 'individuals | institutions | bookings | payments | seats | facilities | rates',
    original_name      VARCHAR(255) NOT NULL,
    file_path          VARCHAR(255) NOT NULL,
    status             VARCHAR(12)  NOT NULL DEFAULT 'uploaded' COMMENT 'uploaded | validated | imported | failed',
    total_rows         INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows         INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows         INT UNSIGNED NOT NULL DEFAULT 0,
    error_report_path  VARCHAR(255) NULL,
    send_invites       TINYINT(1) NOT NULL DEFAULT 0,
    created_by         BIGINT UNSIGNED NULL,
    imported_at        DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_import_batches_type (type, status),
    CONSTRAINT fk_import_batches_created_by FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type    VARCHAR(10)  NOT NULL COMMENT 'staff | account | system',
    actor_id      BIGINT UNSIGNED NULL,
    action        VARCHAR(60)  NOT NULL COMMENT 'e.g. staff.login, rate.update, kyc.document.view',
    entity_type   VARCHAR(40)  NULL,
    entity_id     BIGINT UNSIGNED NULL,
    old_values    JSON NULL,
    new_values    JSON NULL,
    reason        VARCHAR(500) NULL,
    ip            VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_actor (actor_type, actor_id),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_type  VARCHAR(10)  NOT NULL COMMENT 'account | staff',
    recipient_id    BIGINT UNSIGNED NOT NULL,
    type            VARCHAR(50)  NOT NULL COMMENT 'booking.approved, renewal.due, invoice.issued ...',
    title           VARCHAR(190) NOT NULL,
    body            TEXT NULL,
    data            JSON NULL,
    channels        VARCHAR(50)  NOT NULL DEFAULT 'database' COMMENT 'comma list: database,email',
    emailed_at      DATETIME NULL,
    read_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_recipient (recipient_type, recipient_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
