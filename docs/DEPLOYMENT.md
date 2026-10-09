# Deploying Commune (production)

This is the go-live runbook for the Commune Workspace Management System (K-DISC, Kottarakara). It covers the server,
the web server, the `.env` file, permissions, cron, backups, upgrades and the first run. After every install or
upgrade, run **`php bin/console app:check`**. It must report **0 failures**.

---

## 1. Server requirements

| Component | Version / setting |
|---|---|
| OS | Any current Linux (Ubuntu 24.04 LTS / Debian 12 tested) |
| PHP | **8.2 or newer** (8.4 recommended), with PHP-FPM |
| PHP extensions | `pdo_mysql`, `sodium`, `mbstring`, `intl`, `gd` (with JPEG, PNG and WebP), `zip`, `fileinfo`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `zlib`, `iconv`, `ctype`, `openssl`, `json`, `opcache` |
| Database | **MySQL 8.4 LTS**. MariaDB 10.11+ works for development. Use `utf8mb4` / `utf8mb4_unicode_ci`. |
| Web server | nginx 1.24+ or Apache 2.4 with `mod_rewrite`, `mod_headers`, `mod_deflate` |
| TLS | A certificate for the public host name (e.g. Let's Encrypt). HTTPS only. |
| Composer | 2.x (on the server, or build a vendor/ bundle in CI) |
| Node | **Not needed in production.** Built CSS and JS are committed. |
| Mail | An SMTP account for the sender address (e.g. `no-reply@kdisc.kerala.gov.in`), with SPF and DKIM set up |

On Ubuntu, install PHP from the ondrej/php PPA:

```bash
sudo apt install php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-intl php8.4-gd php8.4-zip php8.4-xml php8.4-opcache
# sodium, fileinfo, ctype, iconv, openssl, json and zlib are built in
```

### php.ini (FPM)

```ini
memory_limit = 256M            ; PDFs (dompdf) and XLSX exports
upload_max_filesize = 16M      ; layout photos are ≤ 15 MB, KYC documents ≤ 5 MB, XLSX imports ≤ 5 MB
post_max_size = 20M
max_execution_time = 120
expose_php = Off
display_errors = Off
log_errors = On
session.use_strict_mode = 1    ; the app sets this too

; OPcache — big win for a framework-less app with many small files
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; 0 = fastest; then reload PHP-FPM after every deploy (see §8)
opcache.jit = off                 ; no measurable gain for this workload
```

---

## 2. Layout on disk

```
/var/www/commune/            ← git checkout (owner: deploy user)
├── public/                  ← the ONLY web root
├── storage/                 ← writable by PHP-FPM, never web-served
│   ├── uploads/             ← KYC documents, payment proofs, finance logo/seal (sensitive)
│   ├── pdf/                 ← issued invoices / receipts / credit notes (originals; legal records)
│   ├── imports/             ← bulk-upload temp files (auto-deleted) + error reports
│   ├── logs/  sessions/  cache/  exports/  mail/
└── .env                     ← secrets (APP_KEY, DB and mail passwords)
```

Everything outside `public/` is invisible to the web server. The app streams KYC files and PDFs through
authorised controllers only.

---

## 3. Web server

### nginx

A complete server block is in [`nginx.conf.example`](../nginx.conf.example). It covers:

- HTTP → HTTPS redirect
- `root …/public`, with `try_files` to the front controller
- **every other `*.php` URL returns 404**. `index.php` is `internal`, so URLs never contain `.php`.
- dotfiles denied
- `/assets/` cached for a year. The URLs carry a content hash (`?v=…` from `asset()`), so a deploy busts the cache.
- `/media/` cached for 7 days. Uploaded photos under `/media/uploads/` get a sandboxing CSP.
- gzip for CSS, JS, JSON and SVG
- `client_max_body_size 16M`

### Apache

```apache
<VirtualHost *:80>
    ServerName commune.example.org
    Redirect permanent / https://commune.example.org/
</VirtualHost>

<VirtualHost *:443>
    ServerName commune.example.org
    DocumentRoot /var/www/commune/public

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/commune.example.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/commune.example.org/privkey.pem
    Protocols h2 http/1.1

    <Directory /var/www/commune/public>
        AllowOverride All          # public/.htaccess: clean URLs, caching, deny *.php except index.php
        Require all granted
        Options -Indexes -MultiViews
    </Directory>
    # Belt and braces: nothing outside public/ is reachable even if DocumentRoot is changed by mistake
    <Directory /var/www/commune>
        Require all denied
    </Directory>
    <Directory /var/www/commune/public>
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
    LimitRequestBody 16777216
    ServerSignature Off
</VirtualHost>
```

`public/.htaccess` does the following:

- rewrites to `index.php`
- 301-redirects `*.php` URLs to clean URLs
- denies dotfiles and any other `.php`, `.sql`, `.log`, `.md` or `.sh` file
- sets the long cache for `/assets/` and 7 days for `/media/`
- applies `mod_deflate`

Enable the modules first: `a2enmod rewrite headers deflate proxy_fcgi http2 ssl`.

### Behind a load balancer or reverse proxy

The app ignores `X-Forwarded-For` and `X-Forwarded-Proto` unless the direct peer is listed in `TRUSTED_PROXIES`.
Without it, rate limits see only the proxy's IP. If the proxy terminates TLS, also set
`SESSION_SECURE_COOKIE=true`.

### Security headers

The app sends these on every response (`config/security.php`):

- CSP: self-hosted scripts, no inline scripts, `object-src 'none'`, `frame-ancestors 'self'`
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`. Password-link pages send `no-referrer`.
- `Permissions-Policy`: the camera is allowed only on the document-capture and QR check-in pages
- COOP/CORP
- `Cache-Control: no-store, private` on pages
- HSTS on HTTPS

Do **not** add a second, conflicting CSP in the web server.

#### Content Security Policy and Alpine.js

The CSP still contains `'unsafe-eval'` because the pages use the standard Alpine.js build, which compiles
`x-data` / `@click` expressions with `new Function`. The CSP-safe build (`@alpinejs/csp` 3.15+) was evaluated in
batch 8. Its expression parser rejects 102 of the ~1,195 expressions in the views: statement sequences (`a = 1;
b()`), `if`, arrow functions (`items.filter(u => …)`), template literals in `:style`, optional chaining (`a?.b`)
and globals (`Math`, `confirm`). Most of these are in the two largest screens, the Space Explorer (31) and the
Layout Designer (59). Switching would mean rewriting those as component methods and re-testing every
interaction on both screens. That was out of scope for the hardening pass.

Mitigations in place:

- no inline scripts (`script-src 'self'`)
- all values are escaped with `e()`
- values inside Alpine expressions are JSON-encoded, and confirmation texts use `data-confirm` (batch 8 closed the
  injection points where a floor or facility name reached a `confirm('…')` expression)

**Recommendation:** migrate to `@alpinejs/csp` as a separate task. Move expressions into `Alpine.data()` methods
screen by screen, run the Playwright crawl for console errors, then drop `'unsafe-eval'`. To find the remaining
expressions, run the CSP parser over the views: `@alpinejs/csp/src/parser.js` exports `Tokenizer` and `Parser`,
which Node can run over the `x-*` / `@…` / `:…` attribute values extracted from `resources/views`.

---

## 4. `.env` production checklist

Copy `.env.example` to `.env`, then set every line below. Make the file readable only by the deploy user and the
PHP-FPM group: `chown deploy:www-data .env && chmod 640 .env`.

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | Enables production checks. Refuses `migrate:fresh`, `demo:seed` and demo staff seeding. |
| `APP_DEBUG` | `false` | **Never `true`.** Otherwise stack traces, SQL and file paths are shown to visitors. |
| `APP_URL` | `https://commune.example.org` | Every emailed link (set password, invoices) is built from it. Use https. |
| `APP_KEY` | output of `php bin/console key:generate` | Encrypts Aadhaar numbers (libsodium) and derives the Aadhaar hash key. |
| `LOG_LEVEL` | `info` (or `warning`) | `debug` is noisy |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | dedicated DB user with rights on this schema only | |
| `MAIL_DSN` | `smtp://user:pass@smtp.host:587` | `log://` writes emails, **including password links**, to disk. `app:check` fails with it in production. |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` / `MAIL_REPLY_TO` | real K-DISC addresses | |
| `SESSION_SECURE_COOKIE` | `true` | Cookies only over HTTPS. The app also forces this per HTTPS request. |
| `SESSION_IDLE_MINUTES` | `120` (visitors). Staff sessions use 60. | Idle timeout |
| `SESSION_ABSOLUTE_HOURS` | `12` | Hard cap on one session, however active |
| `TRUSTED_PROXIES` | IP(s) of your reverse proxy / load balancer, or empty | See §3 |
| `RATE_LIMITS` | `true` (default) | Never disable in production |
| `SOCIAL_FACEBOOK` … `SOCIAL_YOUTUBE` | https URLs, or empty | Footer icons appear only when set |

> ### ⚠️ Back up `APP_KEY` separately, today
> Aadhaar numbers are encrypted with a key derived from `APP_KEY`. If it is lost or changed, **every stored Aadhaar
> number becomes unreadable** and duplicate checks stop matching. There is no recovery. Store the key offline (for
> example in K-DISC's password manager or a sealed envelope), apart from the database backups. `app:check` verifies
> that the current key can decrypt a stored number. Key rotation (re-encrypting every row) is not automated yet.

---

## 5. File permissions

```bash
cd /var/www/commune
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 750 {} \;          # directories
sudo find . -type f -exec chmod 640 {} \;          # files
sudo chmod 750 bin/console
sudo chmod -R 2770 storage public/media/uploads    # writable by PHP-FPM (group www-data), setgid keeps the group
sudo chmod 640 .env
```

- PHP-FPM runs as `www-data`. It must write to `storage/*` and `public/media/uploads/` (layout photos).
- Run cron commands as `www-data` too (see §6), so the files they create stay writable for the web.
- KYC documents are created `0640` inside `storage/uploads/kyc/{customer}/`. Bulk-upload temp files are
  `0600` inside `storage/imports/` (`0700`).

---

## 6. Cron

The app's time zone is `Asia/Kolkata`. Add these lines with `sudo crontab -u www-data -e`:

```cron
# Commune — booking lifecycle (activate on start, complete after end, expire unpaid approvals, renewal reminders)
*/15 * * * * cd /var/www/commune && php bin/console bookings:tick   >> storage/logs/cron.log 2>&1
# release expired seat holds (10-minute holds from the Space Explorer)
*/10 * * * * cd /var/www/commune && php bin/console holds:cleanup   >> storage/logs/cron.log 2>&1
# delete expired bulk-upload temp files (they may contain full Aadhaar numbers) + rate-limit housekeeping
7    * * * * cd /var/www/commune && php bin/console imports:cleanup >> storage/logs/cron.log 2>&1
# nightly backup (see §7)
30   1 * * * /usr/local/bin/commune-backup >> /var/log/commune-backup.log 2>&1
```

All three commands are idempotent. Each run leaves a timestamp in `storage/cache/cron-*.stamp`. `app:check` warns
when a stamp is stale: more than 26 h for `bookings:tick`, more than 2 h for the others.

---

## 7. Backups

Back up three things. The database alone is **not** enough, because issued PDFs and KYC files live on disk.

1. **Database**: `mysqldump --single-transaction --routines --triggers commune`
2. **`storage/uploads/`**: KYC documents, payment proofs, finance logo/seal/signature
3. **`storage/pdf/`**: the original issued invoices, receipts, credit notes and refund vouchers (legal records)
4. Also `public/media/uploads/` (layout photos) and a copy of `.env`, but **keep `APP_KEY` stored apart from the
   data** (§4).

Example `/usr/local/bin/commune-backup` (run as root; mode 700):

```bash
#!/usr/bin/env bash
set -euo pipefail
APP=/var/www/commune
DEST=/var/backups/commune
STAMP=$(date +%Y-%m-%d_%H%M)
mkdir -p "$DEST" && chmod 700 "$DEST"

# DB credentials in /root/.my.cnf ([client] user/password), not on the command line
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 commune \
  | gzip -9 > "$DEST/db-$STAMP.sql.gz"
tar -C "$APP" -czf "$DEST/files-$STAMP.tar.gz" storage/uploads storage/pdf public/media/uploads

# encrypt before the copy leaves the server (Aadhaar-bearing data): gpg --encrypt --recipient backup@kdisc …
# rsync/rclone "$DEST" to off-site storage here

# retention: 14 daily, then keep the 1st of each month for 12 months
find "$DEST" -name '*-????-??-0[2-9]_*' -mtime +14 -delete
find "$DEST" -name '*-????-??-[1-3][0-9]_*' -mtime +14 -delete
find "$DEST" -name '*-????-??-01_*' -mtime +365 -delete
```

**Test a restore every quarter:**

1. Load the dump into a scratch database.
2. Untar the files into a scratch checkout.
3. Point a scratch `.env` (with the **same** `APP_KEY`) at them.
4. Run `php bin/console app:check`. The check "APP_KEY decrypts stored Aadhaar" must pass.
5. Open an invoice PDF and a KYC document.

---

## 8. Log rotation

- The app writes daily files `storage/logs/app-YYYY-MM-DD.log` and keeps 30. The app's own logger (`App\Core\Logger`) rotates them; no logrotate
  is needed.
- Logs never contain full Aadhaar numbers, passwords or tokens. A redaction processor masks them even when a
  developer logs them by mistake.
- Rotate `storage/logs/cron.log` with logrotate:

```
/var/www/commune/storage/logs/cron.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    su www-data www-data
    create 0640 www-data www-data
}
```

Sessions are files in `storage/sessions`. PHP's session garbage collector removes them, or rely on the
distribution's `phpsessionclean` timer and point `SESSION_PATH` there.

---

## 9. First install

```bash
sudo -u deploy git clone <repo> /var/www/commune && cd /var/www/commune
composer install --no-dev --optimize-autoloader
cp .env.example .env && php bin/console key:generate      # paste APP_KEY=… into .env, fill in the rest (§4)
mysql -e "CREATE DATABASE commune CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER 'commune'@'localhost' IDENTIFIED BY '<strong password>';
          GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON commune.* TO 'commune'@'localhost';"
php bin/console migrate                 # full schema
php bin/console db:seed                 # centre, floors, seats, prices, facilities, settings
                                        # (with APP_ENV=production the demo staff logins are NOT created)
php bin/console user:create --name="Centre Manager Name" --email=manager@kdisc.kerala.gov.in --role=centre_manager
#   prompts for a password (hidden); or add --invite to email a set-password link instead
# permissions (§5), web server (§3), cron (§6), backups (§7)
php bin/console app:check               # must end with 0 failure(s)
```

Then sign in at `https://<host>/staff/login` and do the following:

1. **Staff users** (`/staff/users`): add the receptionists, Finance Admin and State Admin. Each gets an invite email.
2. **Finance settings** (`/staff/finance/settings`): add the GSTIN, SAC, invoice prefix, bank details, logo, seal
   and signature.
3. **Layout & pricing** (`/staff/layout`): upload the real building and floor photos, check seat positions, publish,
   then confirm the rates.
4. **Facilities**: check which facilities are included and which are add-ons, their prices and their stock.

If the server was ever seeded with demo data (for example a UAT copy promoted to production):

- deactivate `reception@`, `manager@`, `finance@` and `stateadmin@commune.test` in `/staff/users` (`app:check`
  fails while they are active in production)
- delete the demo visitors, whose emails end in `@example.test`, by restoring a clean database instead, because
  bookings and invoices cannot be deleted

---

## 10. Upgrades

```bash
cd /var/www/commune
php bin/console app:check                         # baseline
/usr/local/bin/commune-backup                     # always back up before migrating
git pull --ff-only
composer install --no-dev --optimize-autoloader
php bin/console migrate                           # pending migrations only; never migrate:fresh in production
sudo systemctl reload php8.4-fpm                  # clears OPcache (validate_timestamps=0)
php bin/console app:check                         # 0 failures
```

- Built CSS and JS are committed, so no `npm` runs on the server.
- Asset URLs change automatically when their content changes.
- Migrations are forward-only SQL. To roll back, restore the backup taken just before the upgrade.

---

## 11. UAT checklist (per role)

Use a UAT copy loaded with `php bin/console migrate:fresh --seed && php bin/console demo:seed`. Never do this in
production. The demo staff password is `Password@123`.

**Visitor (online)**

- [ ] Register at `/register`. The math captcha is required. Receive the set-password email, set a password, and
      land in the profile wizard.
- [ ] Complete the 4 wizard steps (individual, then institution) and upload documents (PDF, and a photo from the
      phone camera). Get the Unique Visitor ID.
- [ ] Space Explorer: pick seats, see the 10-minute hold countdown, see the live price. Request a booking. It
      appears under `/my/bookings`.
- [ ] After approval and payment, download the invoice, receipt and allotment letter from `/my/invoices`. The
      ID card PDF has a QR code.
- [ ] Try 3 wrong passwords at `/login`. A captcha appears. 5 wrong passwords lock the account for 15 minutes.
      "Forgot password" works.
- [ ] Try to open another visitor's booking or invoice URL (change the number). You get a 404.

**Receptionist**

- [ ] Assisted registration (`/staff/visitors/new`), with duplicate warnings for the same mobile, email or Aadhaar
- [ ] Book on the staff Space Explorer for a visitor. Log a payment with proof.
- [ ] Check-in desk: scan the QR code on the ID card (camera prompt only on this page), then check in and check out
- [ ] No access to layout, users, finance settings or KYC approval: those menu items are hidden and the URLs return
      403

**Centre Manager**

- [ ] KYC queue: approve and reject (with a reason). The visitor gets an email.
- [ ] Approve a booking request, which sets a pay-by date. Confirm it after payment. Try an early exit and a
      handover.
- [ ] Layout & pricing: edit a draft, publish it, set a rate with a future effective date
- [ ] Staff users:
      - add a receptionist and check the invite email; the link sets a password
      - change a role, deactivate and reactivate
      - send a reset link
      - check that you cannot deactivate yourself, and cannot deactivate the last Centre Manager
- [ ] Bulk upload: download a template, upload with errors, get the error report, then import
- [ ] Audit log shows each of the actions above

**Finance Admin**

- [ ] Verify payments (single and bulk), and raise a query that the front desk answers
- [ ] Issue invoices (advance, and per rent period for deposit bookings). Check the GST split: CGST+SGST in Kerala,
      IGST otherwise.
- [ ] Credit note (full and partial), then a deposit refund after the booking ends
- [ ] Registers and the GSTR-1 summary: download as XLSX and PDF, and check the totals against the invoices
- [ ] Finance settings: the logo, seal and bank details appear on a new invoice PDF

**State Admin**

- [ ] Dashboard: KPIs, trends and occupancy heat-maps. Reports are read-only.
- [ ] Staff users: can manage every role, including other State Admins
- [ ] Audit log is readable. No write actions on bookings, payments or layout.

**Everyone**

- [ ] A mobile phone (390 px wide) shows no horizontal scrolling on any page
- [ ] Sessions expire after inactivity (staff 60 minutes, visitors 120) and after 12 hours in any case
- [ ] Changing your password signs out your other browsers

---

## 12. Known gaps before go-live

- **Two-factor sign-in (TOTP) for staff** is not built. Recommended for Centre Manager, Finance and State Admin
  accounts.
- **Content Security Policy** still needs `'unsafe-eval'` (see §3).
- **APP_KEY rotation** (re-encrypting Aadhaar numbers) is not automated.
- **Antivirus scanning** of uploads is not built. The server could add ClamAV (`clamdscan`) on `storage/uploads`.
- **Online payment gateway**, **e-invoicing (IRN)** and **SMS/WhatsApp** notifications are out of scope. See the
  project overview.

---

## 13. cPanel hosting (commune.kdiscmis.org.in)

The production site is on shared cPanel hosting and is deployed through **cPanel → Git™ Version Control**.
`.cpanel.yml` (repository root) runs `deploy/cpanel/deploy.sh`, which does the whole job.

| Path on the server | What it is |
|---|---|
| `/home/shooting/repositories/kdisc_commune` | cPanel's clone of this repository. Never served. |
| `/home/shooting/apps/commune` | The running app: code, `vendor/`, `storage/` (KYC documents, issued PDFs), `.env`. **Outside `public_html`**, so none of it can be downloaded. |
| `/home/shooting/public_html/commune.kdiscmis.org.in` | The subdomain's document root. It only holds a two-line `index.php` that runs the app's front controller, the app's `.htaccess`, `assets/`, `media/` and a link `media/uploads → apps/commune/public/media/uploads` (layout photos). |

What every deploy does: sync the code (rsync; `.env`, `storage/` and uploaded photos are never touched), copy the
bundled PHP libraries `deploy/vendor` to `vendor/` (no Composer needed), create any missing `storage/` folders, republish the web root (keeping cPanel's own MultiPHP block
in `.htaccess`), run pending migrations, then print `php bin/console app:check`. On the very first deploy it also
creates `.env` from `.env.production` with a fresh `APP_KEY`, and seeds the reference data once (marker file
`storage/.seeded`). Production never gets the demo staff logins.

### One-time setup

1. **PHP 8.2 or newer** (8.4 recommended): cPanel → *MultiPHP Manager* (or *Select PHP Version* on CloudLinux) → set
   `commune.kdiscmis.org.in` to **ea-php84** if offered, otherwise ea-php82/83. The extensions in
   §1 must be enabled for it (WHM → EasyApache 4, or cPanel → *Select PHP Version* on CloudLinux). The deploy script
   finds `/opt/cpanel/ea-php84/root/usr/bin/php` itself and stops with a clear message if an extension is missing.
2. **Subdomain**: cPanel → *Domains* → `commune.kdiscmis.org.in` with document root
   `public_html/commune.kdiscmis.org.in`. Turn on **AutoSSL** (HTTPS is required: `SESSION_SECURE_COOKIE=true`).
3. **Database**: cPanel → *MySQL® Databases* → create `shooting_commune` and user `shooting_commune` with a strong
   password, then add the user to the database with **ALL PRIVILEGES**.
4. **Mailbox**: cPanel → *Email Accounts* → create the sender (e.g. `no-reply@kdiscmis.org.in`). Its SMTP host and
   port are under *Connect Devices*.
5. **Repository**: cPanel → *Git™ Version Control* → *Create* → clone URL of this repository, path
   `/home/shooting/repositories/kdisc_commune`. A private GitHub repository needs an SSH deploy key: generate one in
   cPanel → *SSH Access*, add the public key to GitHub (repository → Settings → Deploy keys, read-only) and clone with
   the `git@github.com:…` URL.
6. **First deploy**: *Manage* → *Pull or Deploy* → *Update from Remote*, then **Deploy HEAD Commit**. Cloning and
   *Update from Remote* only update the clone; nothing is copied until *Deploy HEAD Commit* runs `.cpanel.yml`
   (the button is greyed out while the clone has uncommitted changes). The deploy publishes the site, creates
   `/home/shooting/apps/commune/.env` and skips migrations because the database password is still `CHANGE_ME`.
   The full output is in `/home/shooting/commune-deploy.log` and in cPanel's `~/.cpanel/logs/vc_*_git_deploy.log`.
7. **Back up `APP_KEY`** from that `.env` now, offline (§4).
8. **Edit `/home/shooting/apps/commune/.env`** (File Manager → *Show Hidden Files*, or SSH): set `DB_PASSWORD`,
   `MAIL_DSN` (the `@` in the mailbox name is written `%40`) and, if needed, the other values. The file is mode
   `600`; keep it that way.
9. **Deploy again**. This runs the migrations and seeds the centre, floors, seats, prices, facilities and settings.
10. **First Centre Manager**: cPanel → *Terminal* (or SSH):
    ```bash
    /opt/cpanel/ea-php84/root/usr/bin/php /home/shooting/apps/commune/bin/console user:create \
      --name="Full Name" --email=manager@example.org --role=centre_manager
    ```
11. **Cron**: cPanel → *Cron Jobs*, add the three lines that the deploy log prints at the end (`bookings:tick` every
    15 min, `holds:cleanup` every 10 min, `imports:cleanup` hourly), using the same PHP path.
12. Run the deploy (or `bin/console app:check`) once more: it should show 0 failures. Then follow §9 from
    "Then sign in…" (staff users, finance settings, photos and layout, facilities).

### Every release

Push to the branch the cPanel clone tracks, then *Pull or Deploy* → *Update from Remote* → *Deploy HEAD Commit*.
cPanel only deploys when the clone has no local changes, so never edit files in `/home/shooting/repositories/…`.
Back up the database before a release that adds migrations (cPanel → *Backup* or `mysqldump`).

### Notes

- **"Declaration of Monolog\Logger::emergency(...) must be compatible with PsrExt\Log\LoggerInterface"**: the host
  loads the `psr` PHP extension (common on CloudLinux *Select PHP Version*), which defines the old psr/log 1.x
  interfaces and overrides `vendor/psr`. Fixed in the code: the app no longer uses Monolog, and its own logger fits
  every psr/log version. Unticking `psr` under *Select PHP Version → Extensions* is harmless but not needed.
- **HTTP 500 with no details** (`APP_DEBUG=false` hides them): read `/home/shooting/apps/commune/storage/logs/app-<date>.log`
  and the PHP `error_log` file in `public_html/commune.kdiscmis.org.in/`. After switching PHP versions, check that the
  extensions in §1 are still ticked for the new version.
- **Nothing was deployed?** Check `/home/shooting/commune-deploy.log` (File Manager, home directory). No log at all
  means the tasks never ran: *Deploy HEAD Commit* was not clicked, the clone has local changes, or the clone is on a
  branch without `.cpanel.yml`. Files are copied before any PHP step, so a PHP version or extension problem still
  updates the files and ends the log with an `XX` line saying what is missing.
- The public folder only ever holds `index.php`, `.htaccess`, `favicon.svg`, `assets/` and `media/`. The source
  code goes to `/home/shooting/apps/commune` — that is intended.
- To deploy somewhere else, change the three paths in `.cpanel.yml`. The script refuses unsafe paths (an app
  directory inside `public_html`, or a web root inside the app).
- **No Composer on the server.** Shared hosting often can't run `composer install`, which shows up on the site as
  "Dependencies missing". The production libraries are therefore committed, ready to use, in `deploy/vendor/` (about
  27 MB, without tests or docs). Each deploy copies them to `apps/commune/vendor/`. After changing `composer.json` or
  `composer.lock`, run `bin/build-vendor-bundle.sh` and commit `deploy/vendor`; `tests/Unit/VendorBundleTest` fails
  until you do. Only if `deploy/vendor` is missing does the script fall back to Composer (cPanel's
  `/opt/cpanel/composer/bin/composer`, or a downloaded `composer.phar` with its SHA-256 checked).
- Backups (§7) on cPanel: include `/home/shooting/apps/commune/storage/uploads`, `storage/pdf` and
  `public/media/uploads` along with the database. cPanel's full-account backup covers all of them.
- `DB_HOST=localhost` uses the MySQL socket. If it fails, uncomment `DB_SOCKET` with the server's socket path.
