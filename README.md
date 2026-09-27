# Commune Workspace Management System

K-DISC · Commune "Work Near Home" · **Kottarakara** centre.

A PHP 8.4 / MySQL 8.4 / Tailwind CSS v4 web application with a public website + visitor portal and a staff console
for running the workspace (registrations & KYC, visual seat booking, payments, GST invoices, dashboards).

- Product spec: [`docs/PROJECT_OVERVIEW.md`](docs/PROJECT_OVERVIEW.md)
- Architecture & conventions for contributors: [`CLAUDE.md`](CLAUDE.md)

## Requirements

- PHP **8.4+** with `pdo_mysql`, `sodium`, `mbstring`, `intl`, `gd`, `zip`
- MySQL **8.4** (MariaDB 10.11+ also works for development)
- Composer 2
- Node 20+ / npm — **development only** (building CSS & vendoring JS). The built files are committed, so the
  production server needs no Node.

## Setup

```bash
# 1. PHP dependencies
composer install

# 2. Front-end assets (optional — built output is already committed)
npm install
npm run build              # = npm run vendor:js && npm run build:css

# 3. Environment
cp .env.example .env
php bin/console key:generate   # paste the printed APP_KEY=… into .env
# edit DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD

# 4. Database
mysql -u root -e "CREATE DATABASE commune CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  CREATE USER 'commune'@'localhost' IDENTIFIED BY 'commune';
                  GRANT ALL ON commune.* TO 'commune'@'localhost';"
php bin/console migrate        # create the full schema
php bin/console db:seed        # centre, floors, seats, prices, facilities, settings, staff users
#   or in one go (DROPS ALL TABLES):  php bin/console migrate:fresh --seed

# 5. Run the development server (index.php doubles as the router script so static files are served)
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000> for the public site and <http://localhost:8000/staff/login> for the staff console.

### Seeded staff logins (development only)

All passwords: **`Password@123`** — change or deactivate these before go-live.

| Role            | Email                      |
|-----------------|----------------------------|
| Receptionist    | `reception@commune.test`   |
| Centre Manager  | `manager@commune.test`     |
| Finance Admin   | `finance@commune.test`     |
| State Admin     | `stateadmin@commune.test`  |

### Seeded inventory (spec §7.1)

| Category | Ground (G) | First (F) | Codes | Default price (effective 2026-01-01) |
|---|---|---|---|---|
| Flexi / hot desk | 18 | 24 | `G-FX-01…`, `F-FX-01…` | ₹500/day · ₹4,000/month |
| Dedicated seat | 32 | 64 | `G-DD-01…`, `F-DD-01…` | ₹5,000/month |
| Executive cabins B, D, E | 3 × 3 chairs | — | `G-CB-B` (+ `G-CB-B1…B3`) | ₹8,000/month per cabin |
| Conference room F | 9 chairs | — | `G-CF-F` (+ `G-CF-F1…F9`) | ₹500/hour |

Plus 14 facilities (included, add-ons, landmarks) placed on the floor plans, and settings such as GST 18 %,
SAC 997212 and the invoice prefix `KDISC/CMN`.

## Everyday commands

```bash
composer test                     # PHPUnit
composer lint                     # php -l over all PHP files
composer analyse                  # PHPStan (level 6)
php bin/console migrate:status    # which migrations have run
php bin/console routes            # list routes
npm run watch:css                 # rebuild CSS while editing views
php bin/make-placeholder-plans.php  # regenerate placeholder floor-plan SVGs from the seed layout
```

## Deployment notes

- Point the web server's document root at **`public/`** only. Apache: `public/.htaccess` is included
  (mod_rewrite). nginx: see [`nginx.conf.example`](nginx.conf.example). URLs never contain `.php`.
- Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, and serve over HTTPS (session cookies become
  `Secure` automatically).
- `storage/` must be writable by PHP (logs, sessions, uploads, PDFs). It is outside the web root on purpose.
- Replace the placeholder images in `public/media/` (or update the paths stored in the `buildings`, `floors` and
  `seat_categories` tables) once real photos are available.
