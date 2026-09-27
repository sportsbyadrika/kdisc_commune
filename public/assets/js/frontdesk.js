/**
 * Front-desk helpers for the staff console (batch 5): booking detail, handover, check-in desk, dashboards.
 * Source: resources/js/frontdesk.js → public/assets/js/frontdesk.js (npm run vendor:js).
 * Load it (defer) AFTER space-render.js and BEFORE alpine.min.js, via the view's `head` section.
 *
 * Alpine components
 *   seatMiniMap     read-only seat map (config: MiniMapPresenter::floor() JSON in data-config)
 *                   mode 'view'      booking detail: the booking's seats highlighted (matched by seat_key)
 *                   mode 'occupancy' dashboard: occupied / free, checked-in seats ringed green
 *                   mode 'pick'      handover: click a free seat of cfg.pickCategory → window event 'seat-picked'
 *   handoverForm    handover page: current seat radio + picked seat + live price difference (GET quoteUrl)
 *   paymentForm     payment modal: reference field required/labelled by mode, quick-fill amounts
 *   checkinScanner  /staff/checkin: camera QR scan via BarcodeDetector when available, else typed Unique ID
 */
(() => {
  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;
    const R = window.CommuneSpace;

    // ================================================================== mini-map
    Alpine.data('seatMiniMap', () => ({
      cfg: {},
      picked: null,
      hover: null,
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.$nextTick(() => this.draw());
        window.addEventListener('seat-picked', (e) => { if (e.detail?.map !== this.cfg.floor.slug) { this.picked = null; this.paint(); } });
        window.addEventListener('seat-pick-reset', () => { this.picked = null; this.paint(); });
      },
      get stats() { return this.cfg.stats || {}; },
      draw() {
        const svg = this.$refs.svg;
        if (!svg || !R) return;
        const { width: W, height: H } = this.cfg.floor;
        const geo = { W, H };
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        svg.innerHTML = '';
        R.defs(svg);
        R.el('image', { href: this.cfg.floor.image, x: 0, y: 0, width: W, height: H, preserveAspectRatio: 'none', opacity: 0.9 }, svg);
        const zl = R.el('g', {}, svg); const ul = R.el('g', {}, svg); const sl = R.el('g', {}, svg);
        for (const z of this.cfg.zones) R.zonePolygon(zl, z, geo);
        this.els = {};
        const units = this.cfg.seats.filter((s) => s.parent === null && s.category);
        const chairsOf = {};
        for (const s of this.cfg.seats) if (s.parent) (chairsOf[s.parent] ||= []).push(s);
        for (const u of units.filter((s) => s.kind !== 'seat')) {
          const g = R.unitBlock(ul, u, geo, { line1: u.label, line2: u.occupant ? u.occupant.name : '', hourly: u.category === 'CONF' });
          this.bind(g, u);
          this.els[u.id] = g;
          for (const c of chairsOf[u.id] || []) { const cg = R.chair(sl, c, geo, { child: true }); cg.dataset.unit = u.id; this.els[c.id] = cg; }
        }
        for (const u of units.filter((s) => s.kind === 'seat')) {
          const g = R.chair(sl, u, geo);
          this.bind(g, u);
          this.els[u.id] = g;
        }
        this.paint();
      },
      bind(g, u) {
        const title = R.el('title', {}, g);
        title.textContent = this.label(u);
        if (this.cfg.mode !== 'pick') { g.style.cursor = 'default'; return; }
        g.addEventListener('click', () => this.pick(u));
        g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.pick(u); } });
      },
      label(u) {
        const name = u.kind === 'seat' ? u.code : `${u.label} (${u.code})`;
        if (u.mine) return `${name} — this booking`;
        if (u.occupant) return `${name} — ${u.occupant.name} · ${u.occupant.booking_no}${u.occupant.checked_in ? ' · checked in' : ''}`;
        return `${name} — ${u.status}`;
      },
      selectable(u) { return this.cfg.mode === 'pick' && !u.mine && u.status === 'available' && u.category === this.cfg.pickCategory; },
      statusOf(u) {
        if (this.picked === u.id) return 'mine';
        if (u.mine) return this.cfg.mode === 'pick' ? 'held' : 'mine';
        if (this.cfg.mode === 'pick' && u.status === 'available' && u.category !== this.cfg.pickCategory) return 'blocked';
        return u.status;
      },
      paint() {
        for (const s of this.cfg.seats) {
          const g = this.els?.[s.id];
          if (!g) continue;
          const unit = s.parent ? this.cfg.seats.find((x) => x.id === s.parent) : s;
          if (!unit) continue;
          const st = this.statusOf(unit);
          g.dataset.status = st;
          g.classList.toggle('is-in', !!unit.occupant?.checked_in && st === 'occupied');
          g.classList.toggle('is-dim', this.cfg.mode === 'pick' && !this.selectable(unit) && !unit.mine && this.picked !== unit.id);
          if (s.parent === null) {
            if (this.cfg.mode === 'pick') {
              g.setAttribute('tabindex', this.selectable(unit) ? '0' : '-1');
            } else {
              // read-only maps are one image (role="img"): no interactive children inside it
              g.removeAttribute('tabindex');
              g.removeAttribute('role');
            }
          }
          const glyph = g.querySelector('.glyph');
          if (glyph) glyph.setAttribute('href', `#i-${{ mine: 'check', occupied: 'user', held: 'armchair', blocked: 'lock' }[st] || 'armchair'}`);
        }
      },
      pick(u) {
        if (!this.selectable(u)) {
          if (u.mine) window.Commune.toast?.('That is the booking’s current seat.', 'info');
          return;
        }
        this.picked = u.id;
        this.paint();
        window.dispatchEvent(new CustomEvent('seat-picked', { detail: { id: u.id, code: u.code, label: u.label, kind: u.kind, map: this.cfg.floor.slug } }));
      },
    }));

    // ================================================================== handover form
    Alpine.data('handoverForm', (quoteUrl, firstSeat) => ({
      bookingSeat: firstSeat,
      target: null,
      quote: null,
      busy: false,
      init() {
        window.addEventListener('seat-picked', (e) => { this.target = e.detail; this.refresh(); });
        this.$watch('bookingSeat', () => this.refresh());
      },
      async refresh() {
        if (!this.target || !this.bookingSeat) { this.quote = null; return; }
        this.busy = true;
        try {
          const q = new URLSearchParams({ booking_seat_id: this.bookingSeat, seat_id: this.target.id });
          this.quote = await window.Commune.request(`${quoteUrl}?${q}`);
        } catch (e) { this.quote = { note: 'Could not calculate the price difference.' }; } finally { this.busy = false; }
      },
      clear() { this.target = null; this.quote = null; window.dispatchEvent(new CustomEvent('seat-pick-reset')); },
      money(n) { return window.Commune.formatINR(Math.abs(Number(n || 0)), 2); },
    }));

    // ================================================================== payment form
    Alpine.data('paymentForm', (cfg = {}) => ({
      mode: cfg.mode || 'upi',
      kind: cfg.kind || 'advance',
      amount: cfg.amount || '',
      refLabels: cfg.refLabels || {},
      get needsRef() { return this.mode !== 'cash'; },
      get refLabel() { return this.refLabels[this.mode] || 'Reference'; },
      fill(v, kind = null) { this.amount = Number(v).toFixed(2); if (kind) this.kind = kind; },
    }));

    // ================================================================== QR check-in
    Alpine.data('checkinScanner', () => ({
      supported: 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia,
      scanning: false,
      error: '',
      stream: null,
      async start() {
        this.error = '';
        if (!this.supported) { this.error = 'This browser cannot scan QR codes — type the Unique ID instead.'; return; }
        try {
          const formats = await window.BarcodeDetector.getSupportedFormats?.() || ['qr_code'];
          if (!formats.includes('qr_code')) { this.error = 'QR scanning is not available on this device — type the Unique ID.'; return; }
          this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
          this.scanning = true;
          await this.$nextTick();
          const v = this.$refs.video;
          v.srcObject = this.stream;
          await v.play();
          this.loop();
        } catch (e) {
          this.stop();
          this.error = e?.name === 'NotAllowedError' ? 'Camera permission was denied — type the Unique ID instead.' : 'Could not open the camera — type the Unique ID instead.';
        }
      },
      async loop() {
        if (!this.scanning) return;
        try {
          const codes = await this.detector.detect(this.$refs.video);
          const hit = codes.map((c) => (c.rawValue || '').trim()).find((v) => /^[A-Z0-9-]{6,40}$/i.test(v));
          if (hit) { this.stop(); this.$refs.idInput.value = hit.toUpperCase(); this.$refs.form.submit(); return; }
        } catch (e) { /* frame not ready */ }
        setTimeout(() => this.loop(), 250);
      },
      stop() {
        this.scanning = false;
        for (const t of this.stream?.getTracks() || []) t.stop();
        this.stream = null;
      },
    }));
  });
})();
