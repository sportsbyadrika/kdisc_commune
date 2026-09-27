-- Space model: layout versions -> zones -> seats (cabin/room parents with child chairs),
-- seat categories, rates with effective dates, facilities + placements, seat holds.
-- All map coordinates are PERCENTAGES of the floor image (0-100), so images can be swapped.

CREATE TABLE seat_categories (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(20)  NOT NULL COMMENT 'App\\Enums\\SeatCategory: FLEXI | DEDICATED | CABIN | CONF',
    name             VARCHAR(100) NOT NULL,
    short_name       VARCHAR(40)  NOT NULL,
    description      VARCHAR(500) NULL,
    billing_units    VARCHAR(50)  NOT NULL COMMENT 'comma list: day,month,hour',
    whole_unit_only  TINYINT(1)   NOT NULL DEFAULT 0,
    hourly_only      TINYINT(1)   NOT NULL DEFAULT 0,
    multi_select     TINYINT(1)   NOT NULL DEFAULT 0,
    colour           VARCHAR(20)  NULL,
    icon             VARCHAR(40)  NULL COMMENT 'Lucide icon name',
    image_path       VARCHAR(255) NULL,
    sort_order       SMALLINT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seat_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE layout_versions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    floor_id      BIGINT UNSIGNED NOT NULL,
    version_no    INT UNSIGNED NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'draft' COMMENT 'draft | published | archived',
    notes         VARCHAR(500) NULL,
    created_by    BIGINT UNSIGNED NULL,
    published_by  BIGINT UNSIGNED NULL,
    published_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_layout_versions_floor_no (floor_id, version_no),
    KEY idx_layout_versions_status (floor_id, status),
    CONSTRAINT fk_layout_versions_floor FOREIGN KEY (floor_id) REFERENCES floors (id) ON DELETE CASCADE,
    CONSTRAINT fk_layout_versions_created_by FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_layout_versions_published_by FOREIGN KEY (published_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zones (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    layout_version_id  BIGINT UNSIGNED NOT NULL,
    seat_category_id   BIGINT UNSIGNED NULL COMMENT 'NULL for service areas (pantry, restroom)',
    code               VARCHAR(20)  NOT NULL,
    name               VARCHAR(100) NOT NULL,
    polygon            JSON NULL COMMENT '[[x_pct,y_pct],...]; NULL = use the x/y/w/h rectangle',
    x_pct              DECIMAL(6,3) NOT NULL DEFAULT 0,
    y_pct              DECIMAL(6,3) NOT NULL DEFAULT 0,
    w_pct              DECIMAL(6,3) NOT NULL DEFAULT 0,
    h_pct              DECIMAL(6,3) NOT NULL DEFAULT 0,
    colour             VARCHAR(20)  NULL,
    sort_order         SMALLINT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zones_layout_code (layout_version_id, code),
    KEY idx_zones_category (seat_category_id),
    CONSTRAINT fk_zones_layout FOREIGN KEY (layout_version_id) REFERENCES layout_versions (id) ON DELETE CASCADE,
    CONSTRAINT fk_zones_category FOREIGN KEY (seat_category_id) REFERENCES seat_categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seats (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    zone_id       BIGINT UNSIGNED NOT NULL,
    parent_id     BIGINT UNSIGNED NULL COMMENT 'cabin/room -> its chairs',
    code          VARCHAR(20)  NOT NULL COMMENT 'G-FX-01, G-CB-B, G-CB-B1, G-CF-F',
    label         VARCHAR(50)  NULL,
    kind          VARCHAR(10)  NOT NULL DEFAULT 'seat' COMMENT 'seat | cabin | room',
    capacity      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    x_pct         DECIMAL(6,3) NOT NULL,
    y_pct         DECIMAL(6,3) NOT NULL,
    w_pct         DECIMAL(6,3) NOT NULL,
    h_pct         DECIMAL(6,3) NOT NULL,
    rotation      SMALLINT NOT NULL DEFAULT 0,
    status        VARCHAR(12)  NOT NULL DEFAULT 'available' COMMENT 'available | blocked | maintenance',
    status_from   DATE NULL,
    status_to     DATE NULL,
    tags          VARCHAR(255) NULL COMMENT 'e.g. window,near-pantry',
    notes         VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seats_zone_code (zone_id, code),
    KEY idx_seats_code (code),
    KEY idx_seats_parent (parent_id),
    KEY idx_seats_status (status),
    CONSTRAINT fk_seats_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE,
    CONSTRAINT fk_seats_parent FOREIGN KEY (parent_id) REFERENCES seats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rates (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope           VARCHAR(10)  NOT NULL COMMENT 'category | zone | seat',
    scope_id        BIGINT UNSIGNED NOT NULL,
    unit            VARCHAR(10)  NOT NULL COMMENT 'day | month | hour',
    amount          DECIMAL(12,2) NOT NULL,
    gst_rate        DECIMAL(5,2) NOT NULL DEFAULT 18.00,
    effective_from  DATE NOT NULL,
    effective_to    DATE NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rates_lookup (scope, scope_id, unit, effective_from),
    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES staff_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facilities (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(30)  NOT NULL,
    name          VARCHAR(100) NOT NULL,
    description   VARCHAR(255) NULL,
    icon          VARCHAR(40)  NULL COMMENT 'Lucide icon name',
    emoji         VARCHAR(16)  NULL,
    kind          VARCHAR(10)  NOT NULL COMMENT 'included | addon | landmark',
    unit          VARCHAR(10)  NULL COMMENT 'month | day | use | hour (addons)',
    price         DECIMAL(12,2) NOT NULL DEFAULT 0,
    gst_rate      DECIMAL(5,2)  NOT NULL DEFAULT 18.00,
    stock_qty     INT UNSIGNED NULL COMMENT 'NULL = unlimited',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_facilities_code (code),
    KEY idx_facilities_kind (kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facility_placements (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    facility_id   BIGINT UNSIGNED NOT NULL,
    scope         VARCHAR(10) NOT NULL COMMENT 'floor | zone | seat',
    scope_id      BIGINT UNSIGNED NOT NULL,
    x_pct         DECIMAL(6,3) NULL COMMENT 'Icon position on the floor image; NULL = not drawn',
    y_pct         DECIMAL(6,3) NULL,
    note          VARCHAR(150) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_placements_scope (scope, scope_id),
    KEY idx_placements_facility (facility_id),
    CONSTRAINT fk_placements_facility FOREIGN KEY (facility_id) REFERENCES facilities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seat_holds (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seat_id       BIGINT UNSIGNED NOT NULL,
    holder_type   VARCHAR(10) NOT NULL COMMENT 'account | staff',
    holder_id     BIGINT UNSIGNED NULL,
    session_id    VARCHAR(128) NOT NULL,
    start_at      DATETIME NOT NULL COMMENT 'held period start',
    end_at        DATETIME NOT NULL COMMENT 'held period end',
    expires_at    DATETIME NOT NULL COMMENT 'hold expiry (now + 10 min)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_seat_holds_seat (seat_id, expires_at),
    KEY idx_seat_holds_session (session_id),
    CONSTRAINT fk_seat_holds_seat FOREIGN KEY (seat_id) REFERENCES seats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
