-- Batch 8 — indexes found with EXPLAIN on the report, dashboard, availability and housekeeping queries.
-- Each one replaces a full table scan that grows with every booking / visitor / payment:
--   customers.created_at          KYC funnel, demographics, registration trends (range on created_at)
--   bookings.created_at           bookings report "created" basis, dashboard conversion
--   bookings(status, end_date)    renewals pipeline / reminders (bookings:tick), ending-soon lists
--   bookings(status, payment_due_by)  expiring unpaid approvals (bookings:tick)
--   payments(status, verified_at) "verified today" on the finance dashboard
--   rent_schedules(status, due_on) dues falling due in a period
--   receipts / credit_notes / deposit_refunds by date   registers and the GSTR-1 summary (date ranges without fy)
--   seat_holds.expires_at         holds:cleanup purge
--   login_attempts.attempted_at   30-day housekeeping delete
--   audit_logs.created_at         audit viewer date filters

ALTER TABLE customers
    ADD KEY idx_customers_created (created_at);

ALTER TABLE bookings
    ADD KEY idx_bookings_created (created_at),
    ADD KEY idx_bookings_status_end (status, end_date),
    ADD KEY idx_bookings_status_due (status, payment_due_by);

ALTER TABLE payments
    ADD KEY idx_payments_verified (status, verified_at);

ALTER TABLE rent_schedules
    ADD KEY idx_rent_schedules_status_due (status, due_on);

ALTER TABLE receipts
    ADD KEY idx_receipts_date (receipt_date);

ALTER TABLE credit_notes
    ADD KEY idx_credit_notes_date (note_date);

ALTER TABLE deposit_refunds
    ADD KEY idx_deposit_refunds_date (voucher_date);

ALTER TABLE seat_holds
    ADD KEY idx_seat_holds_expires (expires_at);

ALTER TABLE login_attempts
    ADD KEY idx_login_attempts_at (attempted_at);

ALTER TABLE audit_logs
    ADD KEY idx_audit_created (created_at);
