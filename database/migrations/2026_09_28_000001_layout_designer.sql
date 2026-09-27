-- Batch 4 — Layout & Pricing Designer (spec 5.4).
--
-- Versioning model ("stable keys, immutable versions"):
--   * Every edit happens on a DRAFT layout_versions row whose zones / seats / facility placements are
--     CLONED from the published version. Published and archived rows are never edited again.
--   * seats.seat_key / zones.zone_key are the STABLE identities carried across versions (key = id of the
--     row that first introduced the seat/zone). Publishing flips draft -> published, published -> archived.
--   * booking_seats keep pointing at the exact seat row that was booked (history never changes) and carry
--     seat_key, which AvailabilityService matches against the live version — so bookings made on an older
--     version still occupy the same seat after a publish.
--   * Seat / zone rates (rates.scope = seat|zone) reference seat_key / zone_key, so overrides survive publishes.

ALTER TABLE layout_versions
    ADD COLUMN base_version_id BIGINT UNSIGNED NULL COMMENT 'published version this draft was cloned from' AFTER status,
    ADD COLUMN revision        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'optimistic lock for designer autosave' AFTER base_version_id,
    ADD COLUMN updated_by      BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN archived_at     DATETIME NULL AFTER published_at,
    ADD COLUMN summary         JSON NULL COMMENT 'publish summary: counts + changes' AFTER notes;

ALTER TABLE zones
    ADD COLUMN zone_key BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'stable identity across layout versions' AFTER id,
    DROP INDEX uq_zones_layout_code,
    ADD KEY idx_zones_layout_code (layout_version_id, code),
    ADD KEY idx_zones_key (zone_key);
UPDATE zones SET zone_key = id WHERE zone_key = 0;

ALTER TABLE seats
    ADD COLUMN seat_key BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'stable identity across layout versions' AFTER id,
    DROP INDEX uq_seats_zone_code,
    ADD KEY idx_seats_zone_code (zone_id, code),
    ADD KEY idx_seats_key (seat_key);
UPDATE seats SET seat_key = id WHERE seat_key = 0;

ALTER TABLE booking_seats
    ADD COLUMN seat_key BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'seats.seat_key of the booked seat (matches later layout versions)' AFTER seat_id,
    ADD KEY idx_booking_seats_key_overlap (seat_key, start_date, end_date);
UPDATE booking_seats bs JOIN seats s ON s.id = bs.seat_id SET bs.seat_key = s.seat_key WHERE bs.seat_key = 0;

ALTER TABLE facility_placements
    ADD COLUMN layout_version_id BIGINT UNSIGNED NULL COMMENT 'placements are versioned with the layout' AFTER id;
UPDATE facility_placements fp JOIN zones z ON fp.scope = 'zone' AND z.id = fp.scope_id
   SET fp.layout_version_id = z.layout_version_id WHERE fp.layout_version_id IS NULL;
UPDATE facility_placements fp JOIN seats s ON fp.scope = 'seat' AND s.id = fp.scope_id JOIN zones z ON z.id = s.zone_id
   SET fp.layout_version_id = z.layout_version_id WHERE fp.layout_version_id IS NULL;
UPDATE facility_placements fp JOIN layout_versions lv ON fp.scope = 'floor' AND lv.floor_id = fp.scope_id AND lv.status = 'published'
   SET fp.layout_version_id = lv.id WHERE fp.layout_version_id IS NULL;
DELETE FROM facility_placements WHERE layout_version_id IS NULL;
ALTER TABLE facility_placements
    MODIFY layout_version_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_placements_layout FOREIGN KEY (layout_version_id) REFERENCES layout_versions (id) ON DELETE CASCADE;

ALTER TABLE rates
    ADD COLUMN notes VARCHAR(255) NULL AFTER effective_to,
    ADD KEY idx_rates_scope_unit_to (scope, scope_id, unit, effective_to);

ALTER TABLE buildings
    ADD COLUMN photo_original VARCHAR(255) NULL COMMENT 'original upload name' AFTER photo_h;
ALTER TABLE floors
    ADD COLUMN photo_original VARCHAR(255) NULL COMMENT 'original upload name' AFTER photo_h;
