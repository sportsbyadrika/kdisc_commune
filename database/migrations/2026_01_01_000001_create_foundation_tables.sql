-- Foundation: centres, buildings, floors, staff users, visitor accounts,
-- password tokens, login throttling, settings.
-- Conventions: InnoDB, utf8mb4_unicode_ci, BIGINT UNSIGNED ids, DATETIME timestamps,
-- enum-like columns are VARCHAR validated by PHP enums in app/Enums.

CREATE TABLE centres (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code          VARCHAR(10)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    address       VARCHAR(500) NULL,
    city          VARCHAR(100) NULL,
    district      VARCHAR(100) NULL,
    state         VARCHAR(100) NOT NULL DEFAULT 'Kerala',
    state_code    CHAR(2)      NOT NULL DEFAULT '32',
    pincode       VARCHAR(10)  NULL,
    phone         VARCHAR(20)  NULL,
    email         VARCHAR(190) NULL,
    gstin         VARCHAR(15)  NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_centres_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE buildings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centre_id     BIGINT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    description   TEXT NULL,
    photo_path    VARCHAR(255) NULL COMMENT 'Relative to public/, e.g. media/building.svg',
    photo_w       INT UNSIGNED NULL,
    photo_h       INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_buildings_centre (centre_id),
    CONSTRAINT fk_buildings_centre FOREIGN KEY (centre_id) REFERENCES centres (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE floors (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    building_id     BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    code            VARCHAR(5)   NOT NULL COMMENT 'Seat code prefix: G, F',
    level           SMALLINT     NOT NULL DEFAULT 0,
    photo_path      VARCHAR(255) NULL,
    photo_w         INT UNSIGNED NULL,
    photo_h         INT UNSIGNED NULL,
    hotspot_polygon JSON NULL COMMENT '[[x_pct,y_pct],...] band on the building photo',
    sort_order      SMALLINT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_floors_building_slug (building_id, slug),
    UNIQUE KEY uq_floors_building_code (building_id, code),
    CONSTRAINT fk_floors_building FOREIGN KEY (building_id) REFERENCES buildings (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    centre_id       BIGINT UNSIGNED NULL COMMENT 'NULL = all centres (state admin)',
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    mobile          VARCHAR(20)  NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(32)  NOT NULL COMMENT 'App\\Enums\\StaffRole',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_email (email),
    KEY idx_staff_role (role),
    CONSTRAINT fk_staff_centre FOREIGN KEY (centre_id) REFERENCES centres (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email             VARCHAR(190) NOT NULL,
    password_hash     VARCHAR(255) NULL COMMENT 'NULL until the set-password link is used',
    email_verified_at DATETIME NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'App\\Enums\\AccountStatus',
    terms_accepted_at DATETIME NULL,
    last_login_at     DATETIME NULL,
    last_login_ip     VARCHAR(45) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_email (email),
    KEY idx_accounts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_type  VARCHAR(10) NOT NULL COMMENT 'account | staff (App\\Enums\\HolderType)',
    subject_id    BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)    NOT NULL COMMENT 'sha256 of the emailed token',
    purpose       VARCHAR(10) NOT NULL COMMENT 'set | reset | invite',
    expires_at    DATETIME    NOT NULL,
    used_at       DATETIME    NULL,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_tokens_hash (token_hash),
    KEY idx_password_tokens_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guard         VARCHAR(10)  NOT NULL,
    identifier    VARCHAR(190) NOT NULL COMMENT 'lower-cased email',
    ip            VARCHAR(45)  NOT NULL,
    succeeded     TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_identifier (guard, identifier, attempted_at),
    KEY idx_login_attempts_ip (guard, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    `key`         VARCHAR(100) NOT NULL,
    `value`       TEXT NULL,
    `type`        VARCHAR(10)  NOT NULL DEFAULT 'string' COMMENT 'string|int|float|bool|json',
    `group`       VARCHAR(50)  NOT NULL DEFAULT 'general',
    label         VARCHAR(150) NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
