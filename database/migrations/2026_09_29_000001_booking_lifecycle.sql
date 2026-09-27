-- Batch 5 — booking lifecycle, front-desk operations & payments (spec 6.1-6.3, 7.2).
--
--   bookings        lifecycle timestamps (confirmed/activated/completed), cancellation + early-exit details,
--                   payment_due_by (approved-but-unpaid bookings expire after setting approval_payment_days)
--   booking_seats   seat handover keeps history: the old row is released (released_at, end_date truncated,
--                   transferred_to_id) and a NEW row is added for the new seat — so the unique (booking, seat)
--                   key is dropped (a seat may come back to the same booking later).
--   checkins        per booking seat, matched by seat_key (stable across layout versions)
--   payments        status logged | verified | rejected | refunded | void; payments are never deleted, only
--                   voided with a reason; optional proof file (storage/uploads/payments/...)
--   rent_schedules  monthly rent periods for > 6-month (security deposit) bookings
--   notifications   dedupe_key makes scheduled reminders idempotent (renewal.{booking}.{days})

ALTER TABLE bookings
    ADD COLUMN payment_due_by    DATE NULL COMMENT 'approved bookings must be paid by this date or they expire' AFTER approved_at,
    ADD COLUMN confirmed_at      DATETIME NULL AFTER payment_due_by,
    ADD COLUMN activated_at      DATETIME NULL AFTER confirmed_at,
    ADD COLUMN completed_at      DATETIME NULL AFTER activated_at,
    ADD COLUMN cancel_reason     VARCHAR(500) NULL AFTER cancelled_at,
    ADD COLUMN cancelled_by      VARCHAR(30) NULL COMMENT 'staff:{id} | account:{id} | system' AFTER cancel_reason,
    ADD COLUMN original_end_date DATE NULL COMMENT 'end date before an early exit' AFTER cancelled_by,
    ADD COLUMN exit_reason       VARCHAR(500) NULL AFTER original_end_date;

ALTER TABLE booking_seats
    ADD KEY idx_booking_seats_booking (booking_id),
    DROP INDEX uq_booking_seats;

ALTER TABLE booking_seats
    ADD COLUMN released_reason   VARCHAR(255) NULL AFTER released_at,
    ADD COLUMN transferred_to_id BIGINT UNSIGNED NULL COMMENT 'booking_seats.id of the replacement seat (handover)' AFTER released_reason,
    ADD COLUMN released_by       BIGINT UNSIGNED NULL AFTER transferred_to_id;

ALTER TABLE checkins
    ADD COLUMN booking_seat_id BIGINT UNSIGNED NULL AFTER booking_id,
    ADD COLUMN seat_key        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'seats.seat_key (stable across layout versions)' AFTER seat_id,
    ADD COLUMN method          VARCHAR(10) NOT NULL DEFAULT 'desk' COMMENT 'desk | qr | map | system' AFTER checked_out_at,
    ADD COLUMN checked_out_by  BIGINT UNSIGNED NULL AFTER handled_by,
    ADD KEY idx_checkins_open (booking_id, checked_out_at),
    ADD KEY idx_checkins_key (seat_key, checked_out_at);

UPDATE payments SET status = 'logged' WHERE status = 'pending';
ALTER TABLE payments
    MODIFY status VARCHAR(12) NOT NULL DEFAULT 'logged' COMMENT 'logged | verified | rejected | refunded | void',
    ADD COLUMN proof_path  VARCHAR(255) NULL COMMENT 'relative to storage/uploads' AFTER remarks,
    ADD COLUMN proof_mime  VARCHAR(60)  NULL AFTER proof_path,
    ADD COLUMN proof_name  VARCHAR(255) NULL AFTER proof_mime,
    ADD COLUMN void_reason VARCHAR(500) NULL AFTER proof_name,
    ADD COLUMN voided_by   BIGINT UNSIGNED NULL AFTER void_reason,
    ADD COLUMN voided_at   DATETIME NULL AFTER voided_by,
    ADD CONSTRAINT fk_payments_voided_by FOREIGN KEY (voided_by) REFERENCES staff_users (id) ON DELETE SET NULL;

CREATE TABLE rent_schedules (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id    BIGINT UNSIGNED NOT NULL,
    period_no     SMALLINT UNSIGNED NOT NULL,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    due_on        DATE NOT NULL,
    taxable       DECIMAL(12,2) NOT NULL DEFAULT 0,
    gst           DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount        DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'taxable + gst',
    status        VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open | cancelled (early exit)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rent_schedules_period (booking_id, period_no),
    KEY idx_rent_schedules_due (due_on),
    CONSTRAINT fk_rent_schedules_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
    ADD COLUMN dedupe_key VARCHAR(120) NULL COMMENT 'idempotency key for scheduled notifications' AFTER type,
    ADD UNIQUE KEY uq_notifications_dedupe (dedupe_key);

INSERT IGNORE INTO settings (`key`, `value`, `type`, `group`, label) VALUES
    ('approval_payment_days', '7', 'int', 'booking', 'Approved bookings expire when the required payment is not logged within N days'),
    ('renewal_reminder_days', '15,7,1', 'string', 'booking', 'Renewal reminders are emailed this many days before the end date'),
    ('checkin_open_hour', '7', 'int', 'booking', 'Earliest hour for check-in on the start date (informational)');
