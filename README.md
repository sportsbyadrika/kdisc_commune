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
# (tests) the integration suite uses a separate database:
mysql -u root -e "CREATE DATABASE commune_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                  GRANT ALL ON commune_test.* TO 'commune'@'localhost';"
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

## Visitor registration & KYC (batch 2)

| Flow | Where |
|---|---|
| Online sign-up | `/register` → email link → `/password/set/{token}` → profile wizard `/my/profile/wizard/1…4` → Unique Visitor ID |
| Visitor portal | `/login`, `/my` (ID card + QR, KYC timeline), `/my/profile`, `/my/documents`, `/password/forgot` |
| Assisted (front desk) | `/staff/visitors` (search), `/staff/visitors/new?type=individual\|institution`, `/staff/visitors/{uniqueId}` |
| KYC verification | `/staff/kyc` (Centre Manager): documents side by side with the data, approve / reject with a reason |

**Emails in development.** With `MAIL_DSN=log://default` (the default) nothing is sent: every message is appended to
`storage/logs/mail.log` and saved as `storage/mail/<time>-<subject>.eml` and `.html` — open the `.html` file in a
browser to click the set-password / invite links. Links use `APP_URL`, so set it to the address you browse with.
For real delivery set `MAIL_DSN=smtp://user:pass@host:587` and `MAIL_FROM_ADDRESS`.

**Security notes.** Aadhaar numbers are encrypted with libsodium using `APP_KEY` (keep it secret and backed up —
losing it makes stored Aadhaar numbers unreadable) and only the last 4 digits are ever displayed. KYC documents are
stored in `storage/uploads/kyc/` (outside `public/`), renamed randomly, images re-encoded to strip EXIF, and served
only to the owner or authorised staff. Password links are single-use and expire after 60 minutes
(`settings.password_token_minutes`).

Test identifiers for development (valid checksums, not real people): Aadhaar `2341 2341 2346`, `4991 2345 6783`;
PAN `ABCPE1234F`; institution PAN `AABCK1234L` + GSTIN `32AABCK1234L1ZV`; TAN `TVDK12345E`.

## Space Explorer & booking requests (batch 3)

| Flow | Where |
|---|---|
| Level 1 — building | `/spaces/explore?from=&to=&type=` (home hero date bar and "Book a Seat" link here); floor hotspots with live free counts |
| Level 2 — floor map | `/spaces/explore/{ground-floor\|first-floor}` — tap seats (guests are asked to sign in), zoom/pan, list view, add-ons, live quote |
| Checkout | `/spaces/checkout` → `/spaces/checkout/done/{BK-…}` (visitor must be signed in with a submitted profile; KYC may be pending) |
| My bookings | `/my/bookings`, `/my/bookings/{BK-…}` (status timeline) |
| Receptionist mode | `/staff/spaces` — visitor picker, occupant popovers, manager override (reason, audit-logged), creates **approved** bookings |
| Staff bookings | `/staff/bookings`, `/staff/bookings/{BK-…}` (lifecycle, payments and check-in: batch 5 below) |
| JSON API | `/api/space/*` (site session) and `/staff/api/space/*` (staff session) — see `routes/api.php` |

Selected seats are **held for 10 minutes** (`settings.seat_hold_minutes`, renewable); the map polls every
`settings.availability_poll_seconds` (20 s). Pricing assumptions pending K-DISC confirmation (all in `settings`):
flexi under a month = daily rate × days, a month or more = monthly × months + remaining days at the daily rate, the
daily part capped at one month (`flexi_pricing_rule`, `flexi_daily_cap_monthly`); dedicated seats and cabins pro-rate
partial months as days ÷ 30 (`proration_days_per_month`); tenures over 6 months need a security deposit of
`security_deposit_months` (2) months' rent instead of an advance; IGST applies when the visitor's state code ≠ 32.

## Layout & Pricing Designer (batch 4)

Sign in as `manager@commune.test` → **Layout & pricing** (`/staff/layout`).

- **Floor plans** (`/staff/layout/floors/{floor}`): the live layout opens read-only — click **Edit layout** to start a
  draft (a copy of the live version). Drag a *Seat* from the palette onto the plan, or use *Row of N*, *Grid R×C*,
  *Cabin*, *Conf. room*; draw zones with the rectangle / polygon tools and give them a space type and tint.
  Select with click, Shift-click or a drag lasso; move by dragging or with the arrow keys (Shift = ×10); rotate with
  the handle, `[` `]` or the toolbar; Ctrl+D duplicates, Del deletes, Ctrl+Z / Ctrl+Shift+Z undo/redo, Shift+2 zooms to
  the selection, Space-drag pans, Ctrl+scroll zooms. Snap-to-grid and grid size are in the toolbar. The inspector edits
  code/label/geometry/status (blocked / repairs with dates + note) and attached facilities; its **Pricing** tab shows
  the effective price (seat → zone → base rate) and sets seat/zone overrides, in bulk for multi-selections. Drag
  facilities (🔒 locker, …) from the palette onto a seat, a zone or the floor. Drafts autosave ("Saved ✓").
  **Preview** opens the draft in the visitor explorer; **Publish** runs validation (duplicate codes, seats outside a
  zone, cabins without chairs, missing base rates = errors; removed/moved/blocked booked seats = warnings to confirm).
- **Rates** (`/staff/layout/rates`): base rate per space type with effective-dated history. A new rate closes the
  previous one the day before; a rate that already priced bookings is never changed.
- **Building & floors** (`/staff/layout/building`): building photo, floor hotspot polygons (click to add points, drag,
  Alt-click to delete, click the first point to close), plan images, add/remove floors.
- **Version history** per floor: who created/published what and when; preview or restore an old version as a draft.
- **Facilities** (`/staff/facilities`): the facility master (included / add-on / landmark, price, GST, stock, icon).

### Swapping in real photos
Upload a JPG/PNG/WebP (≤ 15 MB) on *Building & floors* (or *Replace…* in the designer's left panel). It is re-encoded
to WebP under `public/media/uploads/` (random name, EXIF stripped, max 3200 px wide) and its pixel size stored in
`floors.photo_w/h` / `buildings.photo_w/h`. All seat, zone and hotspot coordinates are **percentages of the image**, so
a photo with the same framing lines up immediately; if the new photo is framed differently, open a draft and nudge the
zones/seats (or redraw the hotspots), then publish. The seeded SVG placeholders stay in `public/media/`.

## Booking lifecycle, front desk & payments (batch 5)

**States.** `requested → approved → confirmed → active → completed`, or `rejected` / `cancelled`
(`App\Enums\BookingStatus::transitions()`, enforced by `BookingWorkflow`; every change is audit-logged with actor
and reason and emailed to the visitor and to the centre staff).

| Step | Who / when |
|---|---|
| approve / reject | Centre Manager. Approval **requires verified KYC** (the page shows "Verify KYC first" with a link). Sets a pay-by date (`settings.approval_payment_days`, 7). Rejection needs a reason and frees the seats. |
| confirm | Automatically when the required payment is logged (and KYC is verified); otherwise the **Confirm** button. |
| active | First check-in, or the start date (`bookings:tick`). |
| completed | Check-out on/after the end date, or the end date has passed (`bookings:tick`). |
| cancel | Visitor: own `requested`/`approved` booking from the portal. Staff: any pre-active booking, with a reason. Approved-but-unpaid bookings **expire** after the pay-by date. Active bookings can **end early** (seats released from a date). |

**What must be paid before confirmation** (`App\Services\Payments\ConfirmationRule` — the one place it is defined):
tenure ≤ 6 months (or hourly) → the **full booking amount** (grand total incl. GST); longer → the **security deposit +
the first month's rent** (incl. GST). Longer bookings get a monthly **rent schedule** (`rent_schedules`, due on the
first day of each period) shown on the booking and in the portal.

| Page | Where |
|---|---|
| Bookings console | `/staff/bookings?tab=requests\|payment\|upcoming\|active\|renewals\|completed\|cancelled\|all` + filters (type, floor, dates, source, customer) |
| Booking detail | `/staff/bookings/{BK-…}` — timeline, customer card (masked IDs, KYC), seats + mini-map, dues, payments (log / void), check-in/out, actions |
| Log payment | modal on the booking page: kind, mode (Cash / UPI / NEFT / Cheque / Card …), reference (required unless cash), date, optional proof upload → status `logged` (Finance verifies in the next batch) |
| Handover | `/staff/bookings/{BK-…}/handover` — pick a free seat of the same type on the map; history kept, price difference shown (not billed) |
| Extend | `/staff/bookings/{BK-…}/extend` — linked follow-on booking from the day after the end date, same seats (or replacements), re-quoted at the then-current rate |
| Check-in desk | `/staff/checkin` — type or scan (camera, BarcodeDetector) the visitor's Unique ID QR; also Check in / out from the seat popover in `/staff/spaces` |
| Front-desk dashboard | `/staff/dashboard` (receptionist / Centre Manager) — arrivals, departures, checked in, requests, awaiting payment, renewals due, outstanding dues, live occupancy maps |
| Visitor portal | `/my/bookings/{BK-…}` — timeline, dues, payment history, rent schedule, **Cancel request**, **Renew** (reopens the explorer with the same seats: `/spaces/explore/{floor}?renew=BK-…`) |

**Scheduled commands** — add to cron (the app's timezone is `Asia/Kolkata`):

```cron
*/15 * * * * cd /var/www/commune && php bin/console bookings:tick  >> storage/logs/cron.log 2>&1
*/10 * * * * cd /var/www/commune && php bin/console holds:cleanup  >> storage/logs/cron.log 2>&1
0    * * * * cd /var/www/commune && php bin/console imports:cleanup >> storage/logs/cron.log 2>&1
```

`bookings:tick` activates bookings on their start date, completes them after the end date, expires unpaid approvals
and sends renewal reminders 15 / 7 / 1 days before the end (`settings.renewal_reminder_days`). It is idempotent —
reminders are de-duplicated in `notifications.dedupe_key` — so running it often is safe.

## Finance: verification, GST invoices, receipts, credit notes & PDFs (batch 6)

**Flow.** Front desk logs a payment (`logged`) → Finance verifies it at `/staff/finance/payments` (or bulk-verifies;
a payment with an open **query** to the front desk is skipped until the desk replies from the booking page) → a
**receipt** `RCPT/{FY}/{0001}` is issued in the same transaction (deposits get a *deposit receipt*) → verified money
enters the **invoice queue** (`/staff/finance/invoices`) → Finance issues the **GST tax invoice**
`KDISC/CMN/{FY}/{0001}`. Invoices and receipts are stored as PDF and emailed to the visitor with the PDF attached.

| Rule | |
|---|---|
| ≤ 6 months / hourly (advance) | ONE invoice for the whole booking (seats + add-ons from the price snapshot), once verified payments cover the grand total |
| > 6 months (security deposit) | ONE invoice per rent period (`rent_schedules`), once verified payments cover that period (deposit is filled first) |
| Security deposit | never invoiced — receipt only; settled at the end with a **deposit refund voucher** `DRV/{FY}/{0001}` (adjustments listed) |
| Tax | per line, rounded to paise: CGST 9 % + SGST 9 % when the customer's state code is 32 (Kerala), else IGST 18 %; SAC from settings (997212) |
| Totals | invoice total = the amount billed (booking grand total / rent period amount); any paise difference is shown as *Round off* |
| Numbers | per Indian financial year (Apr–Mar), strictly sequential, allocated under a `number_sequences` row lock in the same transaction as the document — no gaps, no duplicates |
| Corrections | invoices are immutable; **credit notes** `CN/{FY}/{0001}` (cancellation, early exit, handover difference, discount, other) reverse taxable value + GST, full or partial, never more than the invoice. Early exits get a pro-rata suggestion |

**Pages.** `/staff/finance` (dashboard: collections vs dues, GST collected, revenue by space type / add-on, queues,
deposits held), `/staff/finance/payments`, `/staff/finance/invoices?tab=queue|invoices|receipts|credit-notes|deposits`,
`/staff/finance/invoices/{id}` (credit notes), `/staff/finance/registers?type=invoices|receipts|credit-notes|deposits|outstanding`
(+ `.pdf` export), `/staff/finance/settings` (supplier legal name, GSTIN, PAN, state, SAC, GST rate, prefixes, bank,
signatory, logo / signature / seal images, terms). Visitors: `/my/invoices`, booking page *Documents*, allotment letter
`/my/bookings/{BK-…}/allotment-letter.pdf`, ID card `/my/id-card.pdf`.

**PDF storage.** Issued invoices, receipts, credit notes and refund vouchers are rendered once and kept under
`storage/pdf/{invoices|receipts|credit-notes|deposit-refunds}/{FY}/{number-slug}.pdf`; downloads stream the stored
original. *Reprint* re-renders with a **DUPLICATE COPY** watermark (counted + audited). Allotment letters and ID cards
are generated on demand. Back up `storage/pdf/` with the database.

**Fonts.** PDFs use **DejaVu Sans** (bundled with dompdf; includes the ₹ sign) — nothing to install. Font metrics are
cached in `storage/cache/dompdf/`. Malayalam: no Malayalam font ships with the app. To add one, copy
`NotoSansMalayalam-Regular.ttf` (Google Noto, OFL) into `resources/fonts/`, add to `resources/views/pdf/print.css`
`@font-face { font-family: 'Noto Sans Malayalam'; src: url('resources/fonts/NotoSansMalayalam-Regular.ttf'); }` and
use `font-family: 'Noto Sans Malayalam', 'DejaVu Sans'` on Malayalam text. Note that dompdf does not perform complex
script shaping, so conjunct-heavy Malayalam may render imperfectly — keep Malayalam to short labels or pre-render it as an image.

## Dashboards, reports, exports & bulk upload (batch 7)

**Dashboards.** `/staff/dashboard?range=month|last-month|3m|6m|fy|last-fy`
- *State Admin* (read-only): revenue MTD / FYTD (net taxable invoiced), collections, dues outstanding, active bookings,
  occupancy today, 12-month revenue vs collections and occupancy trends, payment status, seats by space type, a
  **heat-map of every floor** (each seat / cabin / room shaded by its share of seat-days occupied in the period) and
  a per-space-type breakdown.
- *Centre Manager*: the front-desk board plus *Centre insights* — heat-map, renewals pipeline (next 30 days), KYC
  queue size, request → confirmation conversion (last 90 days) and dues ageing.

**Reports** — `/staff/reports` lists what the role may open. Every report has filters, a sortable paginated table
with totals, and **Export XLSX / Export PDF** with the same figures and filters:

| Report | What |
|---|---|
| Occupancy | seat-days occupied / available by floor × space type, space type, floor, seat or day (+ daily chart) |
| Bookings summary | bookings, seats, value and confirmation rate by status / source / space type / month |
| Renewals due | bookings ending in the next 7–90 days and whether they are renewed |
| Revenue | invoiced taxable value net of credit notes by month / space type / add-on |
| Collections | payments by mode / purpose / month, verified vs to verify |
| Dues ageing | money due now in 0–30 / 31–60 / 61–90 / 90+ day buckets, per booking or visitor |
| GST summary (GSTR-1) | month or FY: Summary, B2B, B2CL, B2CS (net of its credit notes), credit/debit notes, HSN/SAC — one worksheet each |
| KYC funnel | registered → submitted → verified → booked, online vs reception, time to verify |
| Visitor demographics | individual categories, institution types, channel, home state / nationality |
| Finance registers | invoice / receipt / credit note / deposit / outstanding (Finance) |

List pages (visitors, bookings, payments, invoices & receipts) have an **Export** menu that exports what the page
shows (same filters). XLSX files have a brand-coloured frozen header row, autofilter, ₹ `#,##0.00`, real dates,
column widths and a SUBTOTAL totals row; exports are audited (`report.export`).

**Bulk upload** — `/staff/imports` (Centre Manager). Types: *Individuals, Institutions, Bookings, Payments,
Facilities, Rates*. Download the template (required columns marked `*`, dropdowns, an example row and an
*Instructions* sheet), fill it, upload it (.xlsx, 5 MB / 1,000 rows by default — `import_max_mb`, `import_max_rows`).
Every row is validated with the same rules as the forms (Aadhaar Verhoeff, PAN, GSTIN checksum + PAN + state, TAN,
mobile, email, duplicates in the database *and* in the file, seat availability + price for bookings, balances for
payments) and shown in a **preview** (Aadhaar masked). Confirm *row by row* or *all or nothing*, optionally sending
portal invites; download the **error report** (the failed rows + an Error column, red cells) to fix and re-upload.
The uploaded file stays in `storage/imports/tmp/` (private) only until it is confirmed, discarded or expires
(`import_expiry_minutes`, default 120).

**Also:** audit log viewer `/staff/audit` (Centre Manager, State Admin — filters + old/new diff) and the staff
notification inbox (header bell, `/staff/notifications`).

**Demo / UAT data:** after `php bin/console migrate:fresh --seed` run `php bin/console demo:seed` — ~30 visitors and
~40 bookings over three months with payments, receipts, invoices, a credit note and a renewal, created through the
real services. It refuses to run when `APP_ENV=production`.

## Everyday commands

```bash
composer test                     # PHPUnit (unit + integration against the commune_test DB)
composer lint                     # php -l over all PHP files
composer analyse                  # PHPStan (level 6)
php bin/console migrate:status    # which migrations have run
php bin/console routes            # list routes
php bin/console bookings:tick     # run the booking scheduler now (idempotent)
php bin/console demo:seed         # UAT demo data through the real services (not in production)
php bin/console imports:cleanup   # delete expired bulk-upload temp files
npm run watch:css                 # rebuild CSS while editing views
php bin/make-placeholder-plans.php  # regenerate placeholder floor-plan SVGs from the seed layout
```

## Deployment notes

- Point the web server's document root at **`public/`** only. Apache: `public/.htaccess` is included
  (mod_rewrite). nginx: see [`nginx.conf.example`](nginx.conf.example). URLs never contain `.php`.
- Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_KEY`, and serve over HTTPS (session cookies become
  `Secure` automatically).
- `storage/` must be writable by PHP (logs, sessions, uploads, PDFs, import temp files). It is outside the web root on purpose.
- Replace the placeholder images in `public/media/` (or update the paths stored in the `buildings`, `floors` and
  `seat_categories` tables) once real photos are available.
