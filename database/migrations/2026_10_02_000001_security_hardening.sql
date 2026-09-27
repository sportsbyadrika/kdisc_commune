-- Batch 8 — hardening.
--
--   rate_limits       fixed-window request counters for App\Services\Security\RateLimiter (bucket = "name|ip|user",
--                     hashed). One row per bucket + window; expired rows are purged lazily.
--   staff_users       password_changed_at (sessions signed in before a password change are dropped by the Guard),
--                     created_by / deactivated_at for staff user management (/staff/users).
--   accounts          password_changed_at (same session rule for visitors).

CREATE TABLE rate_limits (
    bucket      CHAR(64)     NOT NULL COMMENT 'sha256 of name|key',
    name        VARCHAR(40)  NOT NULL COMMENT 'limiter name, e.g. quote, holds, export',
    hits        INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at    DATETIME     NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE staff_users
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash,
    ADD COLUMN created_by          BIGINT UNSIGNED NULL AFTER last_login_ip,
    ADD COLUMN deactivated_at      DATETIME NULL AFTER created_by,
    ADD CONSTRAINT fk_staff_created_by FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL;

ALTER TABLE accounts
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;

INSERT IGNORE INTO settings (`key`, `value`, `type`, `group`, label) VALUES
    ('login_captcha_after', '3', 'int', 'security', 'Ask a captcha on sign-in after N failed attempts (email + IP)'),
    ('staff_invite_hours', '72', 'int', 'security', 'Staff invite links stay valid for N hours');
