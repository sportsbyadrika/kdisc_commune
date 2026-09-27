# Commune Workspace Management System — Project Overview & Initial Suggestions

> K-DISC · Commune "Work Near Home" · Kottarakara Centre
> Source: *Business Requirements Document — Commune Workspace Management System* + client feedback (v0.2)
> Status: **Draft for discussion** (v0.2)

### What changed in v0.2

| # | Change | Impact |
|---|---|---|
| 1 | **Online visitor self-registration.** A visitor registers with an email, receives a *set-password* link, then completes their profile | New public **Visitor Portal**. This replaces the BRD's "no public logins" rule |
| 2 | Two visitor types: **Individual** or **Institution**. **Aadhaar is mandatory for both.** Institutions also give institution details and IDs (PAN, GST, TAN, profile) | Updated data capture |
| 3 | A **Receptionist** (staff login) can register the same Individual or Institution on a visitor's behalf, with details and proofs | New staff role and assisted-registration flow |
| 4 | **Visual booking.** Building photo → floor photo with floor plan → seats as selectable **rounded-rectangle chair icons** (BookMyShow / bus-seat style), with **facilities shown as icons/emojis** | New interactive **Space Explorer** |
| 5 | **Price fixing and facility setup** are done on the same diagram page (admin edit mode). Visitors pick **facilities** there too | New **Layout & Pricing Designer** and a facility add-on model |

---

## 1. What we are building

A web platform for the Kottarakara Commune centre with two faces:

1. **Visitor Portal (public):** visitors register, verify their email, set a password, complete their KYC profile, explore the building visually, pick seats and facilities, and track their bookings, dues, invoices and receipts.
2. **Staff Console (internal):** receptionists and managers run the centre. They can do everything a visitor can on the visitor's behalf, plus allocation, check-in/out, payments, pricing and layout. Finance issues GST invoices, and the State Admin monitors performance.

### 1.1 Roles

| Role | Where | Key permissions |
|---|---|---|
| **Visitor** (Individual / Institution) | Visitor Portal | Register, maintain own profile and KYC, browse the Space Explorer, request bookings with seats and facilities, view dues, download invoices and receipts |
| **Receptionist** | Staff Console | Register visitors (assisted), upload proofs, create bookings on the Space Explorer, check-in/out, log payments |
| **Centre Manager** | Staff Console | Everything a Receptionist can do, plus approve online bookings, verify KYC, seat handovers, **Layout & Pricing Designer**, facility master, bulk upload |
| **Finance Admin** | Staff Console | Verified-payment queue, GST invoices, receipts, credit notes, financial reports |
| **State Admin** | Staff Console | Read-only dashboards and reports |

> If the centre has no separate front-desk person, the Receptionist and Centre Manager can be one account. The system keeps them as separate *permissions* either way.

### 1.2 End-to-end flow

```
            ┌──────── Online (Visitor) ─────────┐     ┌──── Assisted (Receptionist) ────┐
            │ Register with email               │     │ Staff login                     │
            │ → "Set your password" email link  │     │ → New visitor (Indiv./Instit.)  │
            │ → Login → Complete profile + KYC  │     │ → Details + proofs uploaded     │
            └───────────────┬───────────────────┘     │ → (optional) invite email so    │
                            │                         │   visitor can log in later      │
                            ▼                         └───────────────┬─────────────────┘
                   Unique Visitor ID generated  ◄─────────────────────┘
                            │
                            ▼
        Space Explorer: Building ► Floor ► pick seats 🪑 + facilities ☕🖨️🅿️
                            │   (seats held for 10 min while choosing)
                            ▼
        Pricing & rules engine: base + facilities + GST; Advance (≤ 6 m) / Security Deposit (> 6 m)
                            │
                            ▼
   Online: booking request → Centre Manager approves → pay at centre (or online gateway, later phase)
   Assisted: confirm on the spot
                            │
                            ▼
        Payment logged → seats "Occupied" → Finance verifies → GST invoice + receipt (PDF, emailed)
```

---

## 2. Recommended technology stack (latest stable, Sept 2026)

| Layer | Choice | Notes |
|---|---|---|
| Language | **PHP 8.4 / 8.5** | Strict types, enums, readonly classes |
| Database | **MySQL 8.4 LTS** (InnoDB, `utf8mb4`) | Transactions and row locks for seat holds and invoice numbers |
| CSS | **Tailwind CSS v4** | CSS-first `@theme` tokens, built with the standalone Tailwind CLI, so there's no Node runtime on the server |
| Interactivity | **Alpine.js 3** (+ htmx for partial reloads) | Seat selection state, modals, steppers |
| Seat map | **Inline SVG over the photo** + `@panzoom/panzoom` | Pinch or scroll zoom and pan on mobile. Drag-to-place in the designer uses native Pointer Events |
| Icons | **Lucide** icons + emoji fallback | Chair, Wi-Fi, AC, printer, locker, parking… |
| Charts | **Chart.js 4** | Dashboards |
| PDF | **dompdf 3.x** | Invoices, receipts, allotment letters, ID slips, reports |
| Excel | **PhpSpreadsheet 5.x** | Bulk upload and download |
| Email | **Symfony Mailer** (SMTP) | Set-password links, booking confirmations, invoices |
| Images | **Intervention Image 3** | Resize and convert building/floor photos to WebP |
| Other | `vlucas/phpdotenv`, `monolog/monolog`, PHPUnit, PHPStan | |

### 2.1 Clean URLs (no `.php` in the address bar)

One front controller, `public/index.php`, plus a router:

```apache
# public/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```
```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
```

| Area | Example URLs |
|---|---|
| Public site | `/`, `/spaces`, `/spaces/ground-floor`, `/facilities`, `/pricing`, `/contact` |
| Visitor auth | `/register`, `/password/set/{token}`, `/login`, `/password/forgot` |
| Visitor portal | `/my/profile`, `/my/kyc`, `/my/bookings`, `/my/bookings/new`, `/my/invoices/{no}/pdf` |
| Staff console | `/staff/login`, `/staff/dashboard`, `/staff/visitors/new`, `/staff/visitors/CMN-KTR-I-2026-00042`, `/staff/bookings`, `/staff/layout/ground-floor`, `/staff/finance/invoices` |

Visitors and staff use **separate sessions and login pages**. Only `public/` is web-accessible. Uploaded KYC documents live outside it and are streamed through an authorised controller.

---

## 3. Proposed folder structure

```
kdisc_commune/
├── public/                       # web root: index.php, .htaccess, assets/
│   └── media/                    # optimised building & floor photos (WebP)
├── app/
│   ├── Controllers/
│   │   ├── Site/                 # public pages, Space Explorer (read-only)
│   │   ├── Portal/               # visitor: auth, profile, KYC, bookings
│   │   └── Staff/                # reception, manager, layout designer, finance, state
│   ├── Models/  Enums/  Middleware/  Core/
│   └── Services/
│       ├── Auth/                 # PasswordSetupTokenService, LoginThrottle
│       ├── Kyc/                  # Aadhaar (Verhoeff), PAN, GSTIN, TAN validators, encryption
│       ├── Space/                # SeatMapService, SeatHoldService, AvailabilityService
│       ├── Pricing/              # PriceResolver, BillingRuleService, GstCalculator
│       ├── Documents/            # PdfService (dompdf), SpreadsheetService
│       └── Notify/               # Mailer
├── resources/
│   ├── views/ (layouts/, site/, portal/, staff/, pdf/, emails/)
│   ├── js/seatmap.js  js/designer.js
│   └── css/app.css               # Tailwind v4 @theme
├── storage/ (uploads/kyc, pdf, exports, logs)   # not web-accessible
├── database/ (migrations, seeds)
├── routes/ (site.php, portal.php, staff.php)
└── composer.json  .env.example
```

---

## 4. Registration & onboarding

### 4.1 Online self-registration (Visitor)

1. **`/register`**: email, name, mobile, type (Individual / Institution), captcha, and acceptance of terms and privacy/consent.
2. The system creates an *unverified* account and emails a **"Set your password" link**. This single-use token is stored hashed, expires in 60 minutes, and doubles as email verification.
3. The visitor sets a password (strength meter, Argon2id hashing) and lands in the portal.
4. **Profile wizard** (stepper with progress bar, saved at each step):
   - **Step 1: Basic details** (prefilled)
   - **Step 2: Identity and KYC** (below)
   - **Step 3: Document uploads** with drag-drop, camera capture on mobile, and a preview
   - **Step 4: Review and submit**
5. The status becomes **KYC Pending**, and the Centre Manager verifies it. The **Unique Visitor ID** is issued at submission. Booking confirmation needs verified KYC; a visitor can browse and request bookings before that.

### 4.2 Assisted registration (Receptionist)

- **`/staff/visitors/new`** uses the same wizard and the same validation, filled in by the receptionist. Proofs can be scanned, uploaded or captured with a webcam.
- An optional **"Send portal invite"** checkbox emails the visitor a set-password link, so a walk-in can use the portal later.
- A **duplicate check** on email, mobile, Aadhaar hash and PAN/GSTIN prevents the same person or institution being registered twice (online and at the desk).
- KYC entered by staff can be marked **Verified** immediately.

### 4.3 Data captured

| Field | Individual | Institution |
|---|---|---|
| Sub-category | **Required**: Remote Worker / Self-Employed / Student / Freelancer | **Required**: Company / Startup / Institution / NGO / Govt. body *(confirm list)* |
| Name, email, mobile, address | Required | Institution name, official email and phone, registered address |
| **Aadhaar number** | **Required** (Indian citizens) | **Required**: Aadhaar of the **authorised signatory / contact person** |
| Passport number | **Required if foreign national** (instead of Aadhaar) | — |
| PAN | Optional | **Required** |
| GSTIN | Optional | **Required** (with an "unregistered" option? *confirm*) |
| TAN | — | **Required** |
| Profile | Short professional summary (required) | Detailed institution profile (required) |
| Authorised signatory | — | Name, designation, email, mobile |
| Documents | Aadhaar, Passport (foreigners), PAN/GST if given, photo | Signatory's Aadhaar, PAN card, GST certificate, TAN proof, registration / incorporation certificate, authorisation letter |

Validation is live in the browser and repeated on the server:
- Aadhaar: 12 digits with a Verhoeff checksum.
- PAN: `AAAAA9999A` format.
- GSTIN: 15 characters with a checksum, and the embedded PAN must match the PAN given.
- TAN: `AAAA99999A` format.
- Mobile numbers: +91 format.

---

## 5. Space Explorer: visual building, floor and seat booking ⭐

A modern, app-like booking experience in the style of **BookMyShow / redBus seat selection**, but on real photos of the Commune building.

### 5.1 Three-level drill-down

```
 ┌──────────────────────── Level 1: BUILDING ────────────────────────┐
 │  [ Real photo of the Commune building ]                           │
 │    ┌──────────────┐  ← hover/tap highlights the floor band        │
 │    │ First Floor  │    "88 seats · 27 free"                        │
 │    ├──────────────┤                                               │
 │    │ Ground Floor │    "63 seats · 12 free"                        │
 │    └──────────────┘                                               │
 └───────────────────────────────────────────────────────────────────┘
                ▼ tap a floor
 ┌──────────────────── Level 2: FLOOR (photo + plan) ────────────────┐
 │ Tabs: [Ground] [First]   Filters: (All)(Flexi)(Dedicated)(Cabin)(Conf) │
 │ ┌─────────────── Floor photo / plan (zoom & pan) ───────────────┐ │
 │ │  Open Area – Flexi 🟢        Enclosed Room – Dedicated          │ │
 │ │  ╭──╮╭──╮╭──╮╭──╮           ╭──╮╭──╮╭──╮╭──╮╭──╮╭──╮           │ │
 │ │  │🪑││🪑││🪑││🪑│           │🪑││🪑││🪑││🪑││🪑││🪑│           │ │
 │ │  ╰──╯╰──╯╰──╯╰──╯           ╰──╯╰──╯╰──╯╰──╯╰──╯╰──╯           │ │
 │ │  📶 ❄️ 🔌                     📶 ❄️ 🔒 🖨️                        │ │
 │ │  ┌ Cabin B ┐┌ Cabin D ┐┌ Cabin E ┐   ┌ Conference F (9) 📽️ ┐    │ │
 │ │  └─────────┘└─────────┘└─────────┘   └─────────────────────┘    │ │
 │ │  ☕ Pantry   🚻 Restroom   🚪 Entry   🅿️ Parking                  │ │
 │ └────────────────────────────────────────────────────────────────┘ │
 │ Legend: 🟩 Available  🟦 Selected  🟧 On hold  🟥 Occupied  ⬜ Blocked │
 └───────────────────────────────────────────────────────────────────┘
                ▼ select seats
 ┌──────── Sticky bottom sheet / right drawer: "Your selection" ─────┐
 │ G-FX-03, G-FX-04 · Flexi · 1 Oct → 31 Oct (1 month)                │
 │ Add facilities:  [☑ 🔒 Locker ₹300/m] [☐ 🅿️ Parking ₹500/m]          │
 │                  [☐ 🖨️ Print pack ₹200] [✓ 📶 Wi-Fi – included]     │
 │ Seats ₹8,000 + Facilities ₹600 + GST 18% ₹1,548 = ₹10,148         │
 │ Payment rule: Advance (≤ 6 months)          [ Continue → ]         │
 └───────────────────────────────────────────────────────────────────┘
```

### 5.2 Seat interaction (visitor and receptionist)

- **Seats are rounded rectangles with a chair icon** (🪑 or Lucide `armchair`), coloured by status. Tap or click to **select**, and tap again to **deselect**. The selected seat gets a checkmark and a brand-colour ring.
- **Dates first**: a date-range bar at the top (and a time slot picker for the conference room) drives availability, so the colours are always correct for the chosen period.
- **Rules are enforced visually**:
  - A **cabin** is selected as one block (all 3 chairs light up together).
  - The **conference room** opens an hourly slot picker.
  - Dedicated seats allow multi-select for institutions. A "select 5 adjacent" helper auto-picks neighbouring seats.
  - The seat count is limited to the "Number of seats" entered.
- **Hover or long-press tooltip**: seat code, category, price, included facilities, and a nearby-facility hint (e.g. "Window side · near pantry").
- **Seat hold**: selected seats are **held for 10 minutes** (with a visible countdown) so an online visitor and the receptionist can't pick the same chair at once. Holds are released on expiry, deselect, or cancel.
- **Live updates**: the availability endpoint is polled every 20–30 s (or uses Server-Sent Events), so seats others take are greyed out without a page reload.
- **Accessibility and mobile**:
  - Every seat is an SVG `<g role="button" aria-pressed>` and can be operated from the keyboard.
  - Colour and icon/pattern are used together, so status isn't shown by colour alone.
  - Pinch-zoom and pan on phones, with a mini-map in the corner.
  - A **list view** toggle is available as a fallback.
- **Receptionist mode** uses the same map, plus a visitor search (by Unique ID, name or mobile) at the top, overbook/force override (with a reason, and audit-logged), and quick check-in/out from the seat popover.

### 5.3 Facilities

- A **facility master** holds an icon/emoji, a name, a type, and a price rule:
  - **Included** (e.g. 📶 Wi-Fi, ❄️ AC, 🔌 power backup, 🚻 restroom, ☕ pantry access)
  - **Chargeable add-on**, priced per month, per day or per use (e.g. 🔒 locker, 🅿️ parking, 🖨️ printing pack, 📽️ projector, 📦 mail handling, 🍱 meal plan, 🎧 headset)
  - **Landmark**, shown on the map only (🚪 entry, 🧯 fire exit, 🛗 lift, ♿ accessible route)
- Facilities are attached to a **floor, zone, or individual seat/cabin**, and appear as icons on the plan and in the seat tooltip.
- Visitors pick add-ons as **toggle chips** in the selection drawer. The price updates live, and GST is applied according to each facility's GST setting.
- Stock-limited facilities (lockers, parking slots) show the number remaining.

### 5.4 Layout & Pricing Designer (Centre Manager): same diagram, edit mode

Admins manage everything on the **same visual page**:

| Tool | What it does |
|---|---|
| **Upload photos** | Building photo (Level 1) and one photo or plan image per floor (Level 2), auto-optimised to WebP |
| **Floor hotspots** | Draw a band or polygon on the building photo for each floor |
| **Zones** | Draw rectangles or polygons for areas (Open Area, Enclosed Room, Cabin B/D/E, Conference F) and set the zone's **category** |
| **Place seats** | Drag chairs from a palette onto the photo. Features: snap-to-grid, **"add row of N"**, rotate, duplicate, and auto-numbering (`G-FX-01…`) |
| **Set price** | Click a zone or seat and edit it in a side panel: base rate (day / month / hour), **effective-from date**, GST rate, and per-seat override (e.g. premium window seat). Supports bulk edit via multi-select (Shift-drag lasso) |
| **Facilities** | Drag a facility icon onto the plan, or assign it to a selected zone or seat, and set whether it is included or chargeable, plus its price |
| **Status** | Mark a seat Blocked / Under maintenance, with an optional date range |
| **Preview and publish** | Changes are saved as a **draft layout version** and go live on *Publish*. Old bookings keep the prices they were created with |

Coordinates are stored as **percentages of the image size** (x, y, w, h, rotation), so the map scales to any screen and a better photo can be swapped in later.

### 5.5 Pricing resolution

```
seat price  = seat override  ??  zone rate  ??  category base rate      (effective on booking start date)
booking     = Σ seats × duration (day / month / hour)  +  Σ facility add-ons
tax         = GST per line (CGST 9% + SGST 9% intra-Kerala, IGST otherwise)
rule        = duration ≤ 6 months → Advance;  > 6 months → Security Deposit
```

---

## 6. Other modules & screens

### 6.1 Visitor Portal
- **Dashboard**: Unique ID card (with QR code), KYC status, active bookings with a countdown to expiry, dues outstanding, "Renew" button
- **My Bookings**: request, view, extend/renew (reopens the Space Explorer with the current seats preselected), cancel request
- **Payments and documents**: dues, receipts, GST invoices (PDF download), allotment letter
- **Profile and KYC**: update details and re-upload documents (changes need re-verification)
- **Notifications**: booking approved, renewal due in 15 / 7 / 1 days, invoice issued

### 6.2 Receptionist
- **Front-desk dashboard**:
  - today's arrivals and departures
  - live occupancy mini-map
  - pending online requests
  - KYC waiting for verification
  - visitor quick-search
- Assisted registration, booking on the map, check-in/out, and payment logging (mode, reference, amount, date)

### 6.3 Centre Manager
- Everything a Receptionist can do, plus:
  - **Approve or reject online booking requests**
  - KYC verification queue with a side-by-side document viewer
  - Seat handover/transfer
  - Renewals list
  - Outstanding dues
- **Layout & Pricing Designer**, facility master and staff users
- **Bulk upload** (XLSX)

### 6.4 Finance Admin
- **Dashboard**: collections vs dues, revenue by category and by facility, GST collected, pending invoice queue
- Verify payments, generate the **GST tax invoice** (sequential number), receipts, and credit notes
- Reports: collection register, GST summary (GSTR-1-friendly XLSX), deposit register, outstanding dues

### 6.5 State Admin
- Read-only: revenue, payment status, active bookings, occupancy heat-map of the floors, seats by category, trends
- Every report exports to PDF and XLSX

---

## 7. Business rules (from the BRD)

### 7.1 Inventory (seeded; afterwards maintained in the Designer)

| Category | Ground | First | Total | Booking unit | Default price |
|---|---|---|---|---|---|
| Flexi / Hot desk (open area) | 18 | 24 | 42 seats | seat | ₹500/day or ₹4,000/month |
| Dedicated seat (enclosed) | 32 | 64 | 96 seats | seat (multi-select for institutions) | ₹5,000/month/seat |
| Executive Cabin B, D, E | 3 | — | 3 cabins × 3 seats | **whole cabin only** | ₹8,000/month/cabin |
| Conference Room F | 1 | — | 9 seats | **room, hourly only** | ₹500/hour |

### 7.2 Payment rules
- **≤ 6 months** requires an **advance payment** before the seat is allocated.
- **> 6 months** requires a **security deposit** logged to secure the tenure, followed by periodic rent billing.
- Online requests stay *Pending approval* until the Centre Manager confirms them and payment is logged. An online payment gateway (e.g. Razorpay) is a later optional phase.

### 7.3 GST and numbering
- Default GST is **18%**: CGST 9% + SGST 9% within Kerala, IGST when the customer's GSTIN state code isn't 32. SAC **997212** (to be confirmed by Finance). A security deposit is not taxable until it is adjusted.
- Number formats:
  - Unique Visitor ID: `CMN-KTR-{I|N}-{YYYY}-{00001}`, where I = Individual and N = Institution
  - Booking no.: `BK-{YYYY}-{000001}`
  - Invoice no.: `KDISC/CMN/{2026-27}/{0001}`, strictly sequential and generated under a row lock
  - Receipt no.: `RCPT/{FY}/{0001}`

---

## 8. Database design (first cut)

| Table | Key columns |
|---|---|
| `accounts` | id, email, password_hash, email_verified_at, status, last_login_at (visitor logins) |
| `staff_users` | id, name, email, password_hash, role (receptionist / centre_manager / finance_admin / state_admin), centre_id, is_active |
| `password_tokens` | id, account_or_staff_id, token_hash, purpose (set / reset / invite), expires_at, used_at |
| `customers` | id, unique_id, account_id (nullable), type (individual / institution), sub_category, name, email, mobile, nationality, address, state_code, profile, pan, gstin, tan, **aadhaar_enc**, aadhaar_hash, aadhaar_last4, passport_no, kyc_status, registered_via (online / reception), registered_by |
| `customer_signatories` | customer_id, name, designation, email, mobile, aadhaar_enc, aadhaar_last4 |
| `customer_documents` | id, customer_id, doc_type, file_path, mime, size, uploaded_by, verified_by, verified_at |
| `centres` → `buildings` | id, name, photo_path, photo_w, photo_h |
| `floors` | id, building_id, name, level, photo_path, photo_w, photo_h, hotspot_polygon (JSON) |
| `layout_versions` | id, floor_id, status (draft / published), published_by, published_at |
| `zones` | id, layout_version_id, name, seat_category_id, polygon (JSON), colour |
| `seat_categories` | id, code (FLEXI / DEDICATED / CABIN / CONF), billing_units, whole_unit_only, hourly_only |
| `seats` | id, zone_id, code, parent_id (cabin → chairs), capacity, x_pct, y_pct, w_pct, h_pct, rotation, status, notes |
| `rates` | id, scope (category / zone / seat), scope_id, unit (day / month / hour), amount, gst_rate, effective_from, effective_to |
| `facilities` | id, name, icon, emoji, kind (included / addon / landmark), unit (month / day / use), price, gst_rate, stock_qty |
| `facility_placements` | id, facility_id, scope (floor / zone / seat), scope_id, x_pct, y_pct |
| `seat_holds` | id, seat_id, holder (account / staff), session_id, start_at, end_at, expires_at |
| `bookings` | id, booking_no, customer_id, source (online / reception), seat_category_id, start_date, end_date, start_time, end_time, seats_count, payment_rule, subtotal, facilities_total, gst_total, grand_total, status (requested / approved / confirmed / active / completed / cancelled / rejected) |
| `booking_seats` | booking_id, seat_id, unit_price, allocated_at, released_at |
| `booking_facilities` | booking_id, facility_id, qty, unit_price, amount |
| `checkins` | booking_id, seat_id, checked_in_at, checked_out_at, handled_by |
| `payments` | id, booking_id, kind (advance / deposit / rent / addon), mode, reference_no, amount, paid_on, logged_by, verified_by, status |
| `invoices`, `invoice_items`, `receipts`, `credit_notes` | GST breakup (taxable, cgst, sgst, igst), pdf_path, sequential number, fy |
| `import_batches`, `audit_logs`, `settings`, `notifications` | as usual |

Double-booking prevention: when a booking is confirmed, a transaction locks the affected `seats` rows and checks for overlapping `booking_seats` and holds for the date range.

---

## 9. PDF generation (dompdf)

- Documents:
  - GST tax invoice
  - payment receipt
  - security deposit receipt
  - booking confirmation / **allotment letter**, including a small static seat-map snapshot that highlights the allotted seats
  - **visitor ID card** with a QR code of the Unique ID
  - reports
- dompdf supports only basic CSS (no flex or grid), so PDF templates use tables and inline or print CSS rather than Tailwind utilities.
- Embed a Noto Sans font so that ₹ and Malayalam render. Load images locally with `isRemoteEnabled=false`.
- Invoice PDFs are stored once and reprints are marked "DUPLICATE COPY".

## 10. XLSX bulk upload & download (PhpSpreadsheet)

- **Templates**: Individuals, Institutions, Bookings, Payments, Seats (x/y optional), Facilities, and Rates. Each has dropdown validations and an instructions sheet.
- **Flow**: upload, then row-by-row validation (Aadhaar Verhoeff, PAN, GSTIN, TAN, dates, seat availability), then a preview of valid and invalid rows, then confirm. Finally, download an **error report XLSX**.
- Imported visitors can optionally receive portal invites (set-password emails) in bulk.
- **Exports**: every list and report has *Export XLSX* and *Export PDF*, and both respect the current filters.

---

## 11. UI / design system (modelled on thekallang.com.sg events page)

> The reference site was blocked from this build environment, so **the exact colours and fonts must still be confirmed** (from screenshots or browser DevTools). All brand values sit in one Tailwind `@theme` block, so swapping them is a single edit.

- **Header**:
  - Sticky, with a slim utility bar (centre name, contact, login/profile).
  - Logo on the left, primary nav with dropdowns (Spaces · Facilities · Pricing · About · Contact), and a bold rounded **"Book a Seat"** CTA on the right.
  - Collapses to a slide-out drawer on mobile.
  - Staff pages use the same header plus a collapsible left sidebar.
- **Hero**: a full-width **building photo** with a headline ("Work near home, Kottarakara") and a quick "Check availability" date bar. This is also the entry point to the Space Explorer.
- **Filter chips / tabs** in the style of the events-category filters (All · Flexi · Dedicated · Cabin · Conference).
- **Cards**: photo, category pill, price badge, facility icons row, and an arrow CTA. Used for space types, bookings and KPI tiles.
- **Seat map** styling:
  - Seat chips use `rounded-xl`.
  - Soft shadow, with a spring scale-up animation on select.
  - Selected seats use the brand colour with a checkmark.
  - Occupied seats are muted and striped.
  - A legend sits below the map.
  - The selection bottom-sheet slides up on mobile.
- **Footer**: dark and multi-column (Spaces, Facilities, Help, Contact with map and address), K-DISC and social links, and a bottom bar with copyright, privacy and terms.

```css
/* resources/css/app.css (placeholders until brand colours are confirmed) */
@import "tailwindcss";
@theme {
  --color-brand-900: #0b1b3f;  --color-brand-600: #1d4ed8;
  --color-accent-500: #e11d74; --color-surface: #f7f7f9; --color-ink: #111827;
  --color-seat-free: #22c55e;  --color-seat-selected: var(--color-brand-600);
  --color-seat-hold: #f59e0b;  --color-seat-taken: #ef4444; --color-seat-blocked: #cbd5e1;
  --font-display: "Inter Tight", ui-sans-serif, system-ui;
  --font-sans: "Inter", ui-sans-serif, system-ui;
}
```

---

## 12. Security & compliance

- **Authentication**:
  - Argon2id password hashing.
  - Set-password and reset tokens are hashed, single-use, and expire after 60 minutes.
  - Login throttling and a captcha on register and login.
  - The session ID is regenerated at login, and visitor and staff sessions are kept separate.
- **Aadhaar** (both visitor types), handled under the Aadhaar Act and UIDAI rules:
  - Explicit consent checkbox at collection.
  - The number is encrypted at rest (libsodium, key in `.env`), with a separate hash kept for duplicate checks.
  - Only the **last 4 digits** are shown in the UI and on PDFs.
  - Visitors are encouraged to upload a **masked Aadhaar**.
  - Document access is role-restricted and audit-logged.
- **Uploads**:
  - MIME and extension whitelist, with a size limit.
  - Files are renamed randomly and stored outside `public/`.
  - Photos are re-encoded, which strips EXIF data.
- **Web and data**:
  - CSRF tokens, PDO prepared statements, output escaping, and CSP headers.
  - Role middleware on every staff route, and ownership checks on every visitor route.
  - Audit log for money, pricing and layout changes. Payments and invoices are never hard-deleted.
- **Operations**: HTTPS only, nightly backups, and a privacy policy page for the DPDP Act 2023.

---

## 13. Open questions for K-DISC

1. **Institution Aadhaar**: whose Aadhaar is it, the authorised signatory's or every member's? Should employees under an institution be recorded individually?
2. **Institution sub-types** and whether unregistered institutions (no GSTIN) are allowed.
3. **Receptionist vs Centre Manager**: are they separate people? Can a receptionist confirm bookings, or only the manager?
4. **Online bookings**: do they need manager approval, or are they auto-confirmed once KYC is verified? Is **online payment** (Razorpay/UPI) wanted, and in which phase?
5. **Facilities list and prices**: which are included and which are chargeable? Are locker and parking quantities limited?
6. **Photos and plans**: a high-resolution building photo, floor photos, and CAD/PDF floor plans with the seat positions.
7. **Conference room**: minimum hours, booking window, and whether the visual picker also needs time slots.
8. **Flexi pricing**: when does the daily rate switch to monthly? Is exactly 6 months treated as Advance?
9. **Security deposit**: how much, the refund rules, and the rent billing cycle for long tenures.
10. **Hold duration** for seats selected online (10 minutes is suggested).
11. **Invoicing**: K-DISC GSTIN, SAC code, invoice prefix, and whether an invoice is issued per payment or monthly.
12. Cancellation, refund, handover and early-exit policies.
13. **Notifications**: email only, or also SMS/WhatsApp?
14. **Branding**: confirm the Kallang-style theme vs K-DISC brand colours, and provide the logos.

---

## 14. Suggested delivery phases

| Phase | Scope | Effort |
|---|---|---|
| **0. Setup** | Skeleton, router, clean URLs, Tailwind v4, header/menu/footer layout, staff auth + roles, seeds | 1 week |
| **1. Accounts & KYC** | Visitor registration, set-password email, profile wizard (Individual/Institution), assisted registration, invites, KYC verification queue, Unique ID | 1.5–2 weeks |
| **2. Space Explorer** | Building → floor → seat map (SVG over photo), select/deselect, holds, availability, facilities chips, live pricing, mobile zoom | 2 weeks |
| **3. Layout & Pricing Designer** | Photo upload, hotspots, zones, drag-place seats, rates with effective dates, facility placement, draft/publish | 1.5–2 weeks |
| **4. Bookings & operations** | Online requests and approval, reception bookings, rules engine, payments, check-in/out, handover, renewals | 1.5 weeks |
| **5. Finance & documents** | Verification queue, GST invoices, receipts, allotment letter, ID card (dompdf), emails | 1.5 weeks |
| **6. Dashboards, reports, bulk** | State, Centre and Finance dashboards, occupancy heat-map, XLSX import/export | 1.5 weeks |
| **7. Hardening & UAT** | Security review, accessibility, performance, tests, UAT, deployment | 1 week |

**Next step:** build a **clickable HTML prototype of the Space Explorer** (building → floor → seat selection with facilities) in the proposed style, so the UX can be approved before development. Then scaffold Phase 0.
