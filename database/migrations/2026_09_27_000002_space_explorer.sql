-- Batch 3 — Space Explorer & booking requests.
-- GST split on bookings (CGST+SGST intra-state, IGST inter-state), terms acceptance, reception override
-- reason, and the new booking/pricing settings (see SettingsSeeder for the canonical list).

ALTER TABLE bookings
    ADD COLUMN tax_mode          VARCHAR(10)   NOT NULL DEFAULT 'intra' COMMENT 'intra (CGST+SGST) | inter (IGST)' AFTER facilities_total,
    ADD COLUMN cgst_total        DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tax_mode,
    ADD COLUMN sgst_total        DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cgst_total,
    ADD COLUMN igst_total        DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sgst_total,
    ADD COLUMN quote_json        JSON NULL COMMENT 'QuoteService snapshot at request time' AFTER deposit_amount,
    ADD COLUMN terms_accepted_at DATETIME NULL AFTER quote_json,
    ADD COLUMN override_reason   VARCHAR(500) NULL COMMENT 'reception override of a blocked/held seat' AFTER terms_accepted_at;

ALTER TABLE booking_seats
    ADD COLUMN gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00 AFTER unit_price,
    ADD COLUMN amount   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'seat total for the whole period (pre-GST)' AFTER gst_rate;

ALTER TABLE seat_holds
    ADD COLUMN customer_id BIGINT UNSIGNED NULL COMMENT 'reception: the visitor the seat is being held for' AFTER holder_id,
    ADD KEY idx_seat_holds_holder (holder_type, holder_id, session_id);

INSERT IGNORE INTO settings (`key`, `value`, `type`, `group`, label) VALUES
    ('flexi_pricing_rule', 'monthly_plus_daily', 'string', 'booking', 'Flexi pricing: under a month = daily rate x days; a month or more = monthly rate x months + remaining days at the daily rate'),
    ('flexi_daily_cap_monthly', '1', 'bool', 'booking', 'Cap the daily-rate part of a flexi booking at one month''s rate'),
    ('proration_days_per_month', '30', 'int', 'booking', 'Partial months of monthly-billed seats are pro-rated as days / N'),
    ('conference_open_hour', '8', 'int', 'booking', 'Conference room bookable from (hour, 24h)'),
    ('conference_close_hour', '20', 'int', 'booking', 'Conference room bookable until (hour, 24h)'),
    ('max_seats_per_booking', '40', 'int', 'booking', 'Maximum seats in one booking request'),
    ('max_booking_months', '36', 'int', 'booking', 'Longest tenure that can be requested online (months)');

UPDATE settings SET `value` = '2', label = 'Security deposit = N months of rent (to be confirmed)'
 WHERE `key` = 'security_deposit_months' AND `value` = '1';
