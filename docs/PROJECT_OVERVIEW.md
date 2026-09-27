# Commune Workspace Management System — Project Overview & Initial Suggestions

> K-DISC · Commune "Work Near Home" · Kottarakara Centre
> Source: *Business Requirements Document — Commune Workspace Management System*
> Status: **Draft for discussion** (v0.1)

---

## 1. What we are building

An internal back-office web app for the Kottarakara Commune centre. The public, visitors and companies never log in. Only three internal roles use it:

| Role | Login | What they do |
|---|---|---|
| **State Admin** | State-wise | Read-only oversight: revenue, payment status, active bookings, seats by category |
| **Centre Manager** | Centre-wise | The only person who enters data: onboards visitors/companies, uploads KYC, creates bookings, assigns seats, handles check-in/check-out and handovers, logs payments |
| **Finance Admin** | Financial | Works through the queue of verified payments and issues GST tax invoices and receipts; views financial reports |

Core flow:

```
Visitor arrives ─► Centre Manager captures profile + KYC ─► Unique ID generated
      ─► Booking configured (type, dates, qty, seat no.) ─► Rules engine: Advance vs Security Deposit
      ─► Payment received & logged ─► Seat marked "Occupied"
      ─► Finance verifies ─► GST invoice (PDF, sequential no.) ─► Receipt
```

---

## 2. Recommended technology stack (latest stable, Sept 2026)

| Layer | Choice | Notes |
|---|---|---|
| Language | **PHP 8.4 / 8.5** | Strict types, enums (for status/role), readonly classes |
| Database | **MySQL 8.4 LTS** (InnoDB, `utf8mb4`) | Foreign keys, transactions for booking + seat allocation |
| CSS | **Tailwind CSS v4** | CSS-first config (`@theme` tokens in `app.css`), built with the standalone Tailwind CLI, so there's no Node runtime on the server |
| Interactivity | **Alpine.js 3** (+ optional htmx) | Modals, tabs, dropdowns, floor-plan selection without an SPA |
| Charts | **Chart.js 4** | Dashboard KPIs and trends |
| PDF | **dompdf 3.x** (`dompdf/dompdf`) | Invoices, receipts, booking confirmations, reports |
| Excel | **PhpSpreadsheet 5.x** (`phpoffice/phpspreadsheet`) | XLSX import templates, bulk upload, exports |
| Routing / DI | Lightweight custom router **or** a micro-framework (Slim 4 / Flight) | Keep it plain PHP. No Laravel unless the team prefers it |
| Other packages | `vlucas/phpdotenv`, `respect/validation` or a custom validator, `monolog/monolog` | |
| Tooling | Composer, PHPUnit, PHPStan (level 6+), PHP-CS-Fixer | |

### 2.1 Clean URLs (no `.php` in the address bar)

All requests go through one front controller (`public/index.php`), and routes are declared in code:

```apache
# public/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

```nginx
# nginx equivalent
location / { try_files $uri $uri/ /index.php?$query_string; }
```

Example routes: `/login`, `/centre/dashboard`, `/visitors/new`, `/visitors/CMN-KTR-2026-00042`, `/bookings/123/checkout`, `/finance/invoices/INV-2026-27-0007/pdf`, `/imports/visitors`.

The web server document root points at `public/` only. Source code, `.env`, and uploaded KYC files stay outside it.

---

## 3. Proposed folder structure

```
kdisc_commune/
├── public/                 # web root
│   ├── index.php           # front controller
│   ├── .htaccess
│   └── assets/ (css/app.css built, js/, img/)
├── app/
│   ├── Controllers/        # Auth, StateDashboard, Centre*, Finance*, Import, Export
│   ├── Models/             # Visitor, Company, Booking, Seat, Payment, Invoice…
│   ├── Services/           # PricingService, BillingRuleService, UniqueIdService,
│   │                       # InvoiceNumberService, PdfService, SpreadsheetService
│   ├── Middleware/         # Auth, Role, Csrf
│   ├── Enums/              # Role, SeatType, BookingStatus, PaymentStatus…
│   └── Core/               # Router, Request, Response, DB (PDO), View, Validator
├── resources/
│   ├── views/              # layouts/ (header, sidebar, footer), pages, pdf/
│   └── css/app.css         # Tailwind v4 source with @theme tokens
├── storage/
│   ├── uploads/kyc/        # private, served via authorised controller
│   ├── pdf/  exports/  logs/
├── database/
│   ├── migrations/  seeds/ # seat inventory seed for Kottarakara
├── config/  routes/web.php
├── tests/
└── composer.json  .env.example
```

---

## 4. Modules & screens

### 4.1 Common
- Login (email + password, Argon2id), forgot/reset password, session timeout, profile/change password
- Role-based sidebar and top header, breadcrumbs, notifications bell (renewals due, invoices pending)
- Audit log (who changed what and when)

### 4.2 State Admin (read-only)
- **Dashboard**: total revenue (MTD/YTD), payment status summary (paid / pending / overdue), active bookings, occupancy % and seats by category (Flexi / Dedicated / Cabin / Conference), trend charts
- Reports: occupancy, revenue by category, bookings list. All filterable, with **PDF + XLSX export**

### 4.3 Centre Manager
- **Dashboard**: live occupancy, today's check-ins and check-outs, interactive seat availability, renewals due (7/15/30 days), outstanding dues with Unique ID
- **Visitors / Companies**
  - Toggle between **Individual** and **Company**
  - Individual: sub-category (Remote Worker, Self-Employed, Student, Freelancer), nationality. **Aadhaar is mandatory for Indians and Passport is mandatory for foreigners**. PAN and GST are optional. Email, mobile and profile summary are required
  - Company: PAN, GST and TAN are mandatory, plus a detailed company profile
  - KYC uploads (Aadhaar, Passport, PAN, GST certificate) as PDF/JPG/PNG with a size limit
  - **Unique ID** generated on save and shown on every screen and PDF
- **Bookings**
  - Seat type, start/end date (plus start/end **time** for the conference room), number of seats, seat number(s)
  - **Interactive floor plan** (Ground / First floor, SVG) for picking seats, colour-coded Available / Reserved / Occupied / Maintenance
  - Live price and rule preview: base amount, GST, and whether an advance or a security deposit is needed
- **Payments**: mark as received with mode (Cash / UPI / NEFT / Cheque / Card), reference no., date and amount. This moves the seat to *Occupied*
- **Operations**: check-in, check-out, seat handover/transfer, renewals/extensions, cancellation
- **Bulk upload** (XLSX): visitors, companies, bookings, payments

### 4.4 Finance Admin
- **Dashboard**: collections vs dues, revenue by category, GST collected (CGST/SGST), pending invoice queue
- **Invoice queue**: payments the Centre Manager has logged, sorted by priority. Finance verifies them and generates a GST tax invoice (dompdf) with a sequential number. Credit notes for cancellations
- **Receipts**, including separate receipts for security deposits
- Reports: collection register, GST summary (B2B/B2C, GSTR-1 friendly XLSX), outstanding dues, deposit register

---

## 5. Business rules (from the BRD, with suggested interpretations)

### 5.1 Inventory (seeded at install)

| Category | Ground | First | Total | Unit | Price |
|---|---|---|---|---|---|
| Flexi / Hot desk (open area) | 18 | 24 | 42 seats | seat | ₹500/day or ₹4,000/month |
| Dedicated seat (enclosed) | 32 | 64 | 96 seats | seat | ₹5,000/month/seat |
| Executive Cabin (B, D, E) | 3 | — | 3 cabins (3 seats each) | whole cabin | ₹8,000/month/cabin |
| Conference Room F | 1 | — | 9 seats | room | ₹500/hour |

Suggested seat codes: `G-FX-01…18`, `F-FX-01…24`, `G-DS-01…32`, `F-DS-01…64`, `CAB-B/D/E`, `CONF-F`.
Keep prices in a **`rate_cards` table with effective dates**, not hard-coded, so price changes don't alter old invoices.

### 5.2 Payment rule engine
- Duration = end date − start date (inclusive).
- **≤ 6 months** → **Advance payment** of the full booking value must be logged before a seat is allocated.
- **> 6 months** → a **Security Deposit** must be logged before the extended tenure is confirmed. Monthly rent is then billed per cycle.
- **Cabins** can only be booked as a whole unit. The **conference room** is hourly only. **Flexi** uses the day rate below a month and the monthly rate from a month up.

### 5.3 GST
- Configurable rate (default **18%**). Kerala is an intra-state supply, so split it as **CGST 9% + SGST 9%**. Use IGST when the customer's GSTIN state code is not 32.
- Show SAC code **997212** (rental of non-residential property; confirm with finance).
- A security deposit is **not** taxable until it is adjusted against rent.

### 5.4 Identifiers
- Unique Visitor ID: `CMN-KTR-{I|C}-{YYYY}-{00001}`
- Invoice no.: `KDISC/CMN/{FY 2026-27}/{0001}`. Strictly sequential per financial year and generated inside a DB transaction with a row lock, so there are no gaps or duplicates.
- Receipt no.: `RCPT/{FY}/{0001}`

---

## 6. Database design (first cut)

| Table | Key columns |
|---|---|
| `users` | id, name, email, password_hash, role (state_admin / centre_manager / finance_admin), centre_id, is_active, last_login_at |
| `centres` | id, name, code (KTR), address, gstin (lets the system grow beyond one centre later) |
| `floors` | id, centre_id, name (Ground / First) |
| `seat_types` | id, code (FLEXI, DEDICATED, CABIN, CONF), billing_unit (day/month/hour), whole_unit_only |
| `rate_cards` | id, seat_type_id, unit, amount, gst_rate, effective_from, effective_to |
| `seats` | id, floor_id, seat_type_id, code, parent_id (cabin → seats), capacity, status, svg_x/svg_y |
| `customers` | id, unique_id, type (individual/company), name, email, mobile, nationality, sub_category, profile_summary, **aadhaar_enc**, aadhaar_last4, passport_no, pan, gstin, tan, address, state_code |
| `customer_documents` | id, customer_id, doc_type, file_path, mime, size, uploaded_by |
| `bookings` | id, booking_no, customer_id, seat_type_id, start_date, end_date, start_time, end_time, seats_count, payment_rule (advance/deposit), base_amount, gst_amount, total_amount, status (draft/confirmed/active/completed/cancelled) |
| `booking_seats` | booking_id, seat_id, allocated_at, released_at (tracks handovers) |
| `checkins` | id, booking_id, seat_id, checked_in_at, checked_out_at, handled_by |
| `payments` | id, booking_id, customer_id, kind (advance/deposit/rent), mode, reference_no, amount, paid_on, logged_by, verified_by, verified_at, status |
| `invoices` | id, invoice_no, fy, customer_id, booking_id, taxable, cgst, sgst, igst, total, pdf_path, issued_by, issued_at |
| `invoice_items` | invoice_id, description, sac, qty, unit_price, amount |
| `receipts`, `credit_notes` | as for invoices |
| `import_batches` | id, type, file, rows_total, rows_ok, rows_failed, error_file, created_by |
| `audit_logs` | id, user_id, action, entity, entity_id, old_json, new_json, ip, created_at |
| `settings` | key / value (GST rate, invoice prefix, company letterhead, bank details) |

---

## 7. PDF generation (dompdf)

- Templates are ordinary PHP/HTML views in `resources/views/pdf/`. Use **inline CSS or a small print stylesheet**, because dompdf does not run Tailwind's modern CSS (no flex/grid support, only limited CSS3).
- Documents: **GST tax invoice**, payment receipt, security deposit receipt, booking confirmation / allotment letter, visitor ID slip (with QR code of the Unique ID), and dashboard reports.
- Embed a Unicode font that includes **₹** and Malayalam (for example Noto Sans / Noto Sans Malayalam). Enable `isRemoteEnabled=false` and load the logo from local disk.
- Store every generated invoice PDF so reprints are identical. Mark duplicates "DUPLICATE COPY".

## 8. XLSX bulk upload & download (PhpSpreadsheet)

1. **Download template** for each entity (visitors, companies, bookings, payments), with a header row, dropdown data-validation lists (sub-category, seat type), and an *Instructions* sheet.
2. **Upload** → the server parses and validates each row (required fields, Aadhaar 12-digit Verhoeff check, PAN `AAAAA9999A`, GSTIN 15-char with checksum, TAN, email/mobile, date ranges, seat availability).
3. A **preview screen** shows valid and invalid rows. On confirm, valid rows are imported in a transaction.
4. An **error report XLSX** can be downloaded, with the failing rows plus an "Error" column.
5. Every import is logged in `import_batches`.
6. **Exports**: every list/report has *Export XLSX* and *Export PDF*, respecting the current filters. Use chunked reading for large files.

---

## 9. UI / design system (modelled on thekallang.com.sg events page)

> **Note:** the reference site could not be fetched from this build environment (the network proxy blocked it). The layout below follows the structure of that page. The **exact colour hex codes, fonts and logo treatment must be confirmed** from the live site (screenshots, or DevTools → computed styles) before the Tailwind theme is finalised. The tokens are placeholders in `@theme` so they can be swapped in one place.

**Layout pattern to replicate**
- **Header**: sticky, full-width, with a slim utility bar on top (centre name, date/time, logged-in user, logout). The main bar has the logo on the left, primary navigation in the centre with dropdown / mega-menu panels, and a bold rounded CTA on the right (e.g. "+ New Booking"). It collapses to a hamburger slide-out drawer on mobile.
- **Page hero / banner**: a large banner with a strong headline and a breadcrumb, used as the header of each module ("Bookings", "Invoices").
- **Filter chips / tabs** above card grids, in the same style as the event category filters (All · Flexi · Dedicated · Cabin · Conference). Use a date-range picker and search as on the events listing.
- **Cards**: image/illustration or colour block on top, category tag pill, date badge, title, meta row, and an arrow link. Use these for bookings, renewals and KPI tiles.
- **Tables** for data-heavy screens, with sticky header, zebra rows, and a row action menu.
- **Footer**: dark, multi-column (quick links, reports, help/support, centre address and contact), social/K-DISC links, and a bottom bar with copyright, version and privacy/terms.

**Tailwind v4 theme skeleton** (`resources/css/app.css`)
```css
@import "tailwindcss";

@theme {
  /* PLACEHOLDERS: replace with the exact values from the reference site */
  --color-brand-900: #0b1b3f;   /* deep header / footer background */
  --color-brand-600: #1d4ed8;   /* primary actions */
  --color-accent-500: #e11d74;  /* CTA / highlights */
  --color-accent-300: #f9a8d4;
  --color-surface:    #f7f7f9;
  --color-ink:        #111827;

  --font-display: "Inter Tight", ui-sans-serif, system-ui;  /* headings */
  --font-sans:    "Inter", ui-sans-serif, system-ui;        /* body */
  --radius-card: 1.25rem;
}
```

Status colours stay fixed for usability: Available = green, Reserved = amber, Occupied = red/brand, Maintenance = grey.

---

## 10. Security & compliance

- PDO prepared statements everywhere, CSRF tokens, output escaping, strict `SameSite` session cookies, `session_regenerate_id` on login, and login rate-limiting/lockout.
- **Aadhaar handling**: the Aadhaar Act and UIDAI rules restrict storage. Encrypt the number at rest (libsodium, key in `.env`), show only the **last 4 digits** in the UI and on PDFs, and restrict document downloads to authorised roles. Uploaded scans also need a "masked Aadhaar" advisory.
- Uploads: validate MIME type and extension, randomise file names, store outside `public/`, and serve through a controller that checks the role.
- Role middleware on every route, plus a full audit trail for money-related changes. Payments and invoices are never hard-deleted.
- Nightly MySQL backups and HTTPS only.

---

## 11. Gaps / questions to clarify with K-DISC

1. **Conference room**: the BRD only lists date fields. Hourly booking needs a start/end time and clash detection. Is there a minimum number of hours?
2. **Flexi pricing**: when does ₹500/day switch to ₹4,000/month? Is it automatic at ≥ 8 days, or is it the manager's choice?
3. **Exactly 6 months**: is it treated as advance? The BRD says "≤ 6 months" is advance.
4. **Security deposit**: what amount (e.g. 1, 2 or 3 months' rent)? Is it refundable, and what are the adjustment rules? Long-term bookings will also need a monthly billing cycle.
5. **Partial payments / instalments**: are they allowed?
6. **Invoice timing**: is one invoice issued per payment or per month? Is there a GSTIN for K-DISC Kottarakara, and what are the SAC code and invoice prefix?
7. **Cancellation, refund and early-exit** policy, and credit notes.
8. What does "**seat handover**" mean exactly: a change of seat, or a transfer to another person in the same company?
9. **Company bookings**: should individual employees be recorded under a company booking?
10. **Notifications**: should renewals and receipts be sent to the customer by email or SMS/WhatsApp?
11. **Multi-centre future**: the State login suggests more centres later. The schema already includes `centre_id`.
12. **Floor plan drawings** (CAD/PDF) for Ground and First floors, to build the interactive SVG.
13. **Branding assets**: K-DISC / Commune logos, letterhead, and confirmation that the Kallang-style colour theme is wanted instead of K-DISC's own brand colours.

---

## 12. Suggested delivery phases

| Phase | Scope | Rough effort |
|---|---|---|
| **0. Setup** | Repo, Composer, router, clean URLs, Tailwind v4 build, layout (header/menu/footer), auth + roles, seeds | 1 week |
| **1. Onboarding** | Customers (individual/company), validation, KYC uploads, Unique ID, customer profile page | 1–1.5 weeks |
| **2. Bookings & seats** | Rate cards, rule engine, booking form, SVG floor plan, allocation, check-in/out, handover, renewals | 2 weeks |
| **3. Payments & Finance** | Payment logging, finance queue, GST invoice/receipt PDFs (dompdf), sequential numbering, credit notes | 1.5 weeks |
| **4. Dashboards & reports** | State, Centre and Finance dashboards (Chart.js), filterable reports, PDF/XLSX export | 1 week |
| **5. Bulk import/export** | XLSX templates, validation preview, error reports, import history | 1 week |
| **6. Hardening & UAT** | Audit log, security review, backups, tests, UAT with the Centre Manager, deployment | 1 week |

**Next step:** once the open questions in §11 and the brand colours in §9 are confirmed, scaffold Phase 0 on this branch: the skeleton app, the layout matching the reference design, login, and the three role dashboards with seeded data.
