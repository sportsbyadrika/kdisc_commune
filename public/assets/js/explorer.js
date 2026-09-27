/**
 * Space Explorer (spec 5) — Level 1 building + Level 2 floor seat map, shared by the public site
 * (/spaces/explore*) and receptionist mode (/staff/spaces).
 * Source: resources/js/explorer.js → public/assets/js/explorer.js (npm run vendor:js).
 * Load it (defer) BEFORE alpine.min.js, via the view's `head` section, together with panzoom.min.js.
 *
 * Alpine components
 *   buildingExplorer   Level 1: hotspot polygons on the building photo + live free counts per floor
 *   spaceExplorer      Level 2: SVG seat map over the floor image, selection drawer, holds, quote, polling
 *
 * Data flow (JSON API, routes/api.php):
 *   GET  {api}/floors/{floor}/map        geometry %, statuses, prices, facilities, add-ons, my selection
 *   GET  {api}/availability?version=     poll — statuses + conference slots, "unchanged" when the hash matches
 *   POST {api}/holds  DELETE {api}/holds[/{seat}]  POST {api}/holds/renew     seat holds (server enforces the rules)
 *   POST {api}/quote                      live price breakdown (GST split, payment rule)
 *   staff only: GET {api}/customers?q=, POST {api}/book
 *
 * The SVG is built with DOM calls (Alpine x-for does not work inside <svg>); Alpine drives everything else.
 */
(() => {
  const NS = 'http://www.w3.org/2000/svg';
  const STATUS = { available: 'Available', mine: 'Selected', held: 'On hold', occupied: 'Booked', blocked: 'Blocked' };
  const GLYPH = { available: 'armchair', mine: 'check', held: 'hourglass', occupied: 'user', blocked: 'lock' };
  const UNIT_SHORT = { day: 'day', month: 'mo', hour: 'hr', use: 'use' };
  const money = (n, d = 0) => window.Commune.formatINR(Number(n || 0), d);
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const hourLabel = (h) => { const hh = ((h + 11) % 12) + 1; return `${hh}${h < 12 || h === 24 ? ' am' : ' pm'}`; };
  const fmtDate = (s) => (s ? new Date(`${s}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  const fmtShort = (s) => (s ? new Date(`${s}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '');

  function el(tag, attrs = {}, parent = null) {
    const n = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v);
    if (parent) parent.appendChild(n);
    return n;
  }
  function glyph(name, attrs, parent) {
    const u = el('use', attrs, parent);
    u.setAttribute('href', `#i-${name}`);
    return u;
  }
  function priceText(rates = {}) {
    const parts = [];
    for (const u of ['hour', 'day', 'month']) if (rates[u] != null) parts.push(`${money(rates[u])}/${UNIT_SHORT[u]}`);
    return parts.join(' · ');
  }
  function fromPrice(rates = {}) {
    for (const u of ['hour', 'day', 'month']) if (rates[u] != null) return `${money(rates[u])}/${UNIT_SHORT[u]}`;
    return '';
  }

  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // ================================================================== Level 1 — building
    Alpine.data('buildingExplorer', () => ({
      cfg: {},
      floors: [],
      from: '',
      to: '',
      type: '',
      hover: null,
      pinned: null,
      loading: false,
      tipX: 50,
      tipY: 50,
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.floors = this.cfg.floors || [];
        ({ from: this.from, to: this.to, type: this.type } = this.cfg.filters);
      },
      get today() { return this.cfg.today; },
      floor(slug) { return this.floors.find((f) => f.slug === slug) || null; },
      points(f) { return (f.hotspot || []).map((p) => p.join(',')).join(' '); },
      free(f) { return this.type ? (f.by_category[this.type]?.free ?? 0) : f.free; },
      total(f) { return this.type ? (f.by_category[this.type]?.chairs ?? 0) : f.chairs; },
      pct(f) { const t = this.total(f); return t ? Math.round((this.free(f) / t) * 100) : 0; },
      href(f) {
        const q = new URLSearchParams({ from: this.from, to: this.to });
        if (this.type) q.set('type', this.type);
        return `${this.cfg.floorUrl.replace('__FLOOR__', f.slug)}?${q}`;
      },
      enter(f) {
        this.hover = f.slug;
        const xs = f.hotspot.map((p) => p[0]); const ys = f.hotspot.map((p) => p[1]);
        this.tipX = (Math.min(...xs) + Math.max(...xs)) / 2;
        this.tipY = Math.min(...ys);
      },
      tap(f, e) {
        // touch: first tap previews, second tap opens the floor
        if (window.matchMedia('(hover: none)').matches && this.pinned !== f.slug) {
          e.preventDefault();
          this.pinned = f.slug;
          this.enter(f);
        }
      },
      get active() { return this.floor(this.pinned || this.hover); },
      fromChanged() { if (this.to < this.from) this.to = this.from; this.refresh(); },
      async refresh() {
        if (!this.from || !this.to || this.to < this.from) return;
        this.loading = true;
        try {
          const q = new URLSearchParams({ from: this.from, to: this.to });
          const data = await window.Commune.request(`${this.cfg.api}/building?${q}`);
          for (const f of data.floors) {
            const mine = this.floor(f.slug);
            if (mine) Object.assign(mine, { chairs: f.chairs, free: f.free, by_category: f.by_category });
          }
          const url = new URL(window.location.href);
          url.searchParams.set('from', this.from); url.searchParams.set('to', this.to);
          if (this.type) url.searchParams.set('type', this.type); else url.searchParams.delete('type');
          window.history.replaceState(null, '', url);
        } catch (e) { /* keep last numbers */ } finally { this.loading = false; }
      },
      catLabel(code) { return (this.cfg.categories || []).find((c) => c.code === code)?.short || code; },
    }));

    // ================================================================== Level 2 — floor map
    Alpine.data('spaceExplorer', () => ({
      cfg: {},
      from: '',
      to: '',
      filter: '',
      view: 'map',
      seatsNeeded: 1,
      loading: false,
      map: null,
      byId: {},
      units: [],
      chairsOf: {},
      zonesById: {},
      els: {},
      selection: null,
      pending: {},
      deadline: 0,
      remaining: 0,
      addons: {},
      quote: null,
      quoteBusy: false,
      quoteError: '',
      tip: { show: false, x: 0, y: 0, below: false, id: null, fac: null },
      toasts: [],
      sheet: false,
      signIn: false,
      switchTo: null,
      slot: { open: false, unit: null, date: '', start: null, end: null, slots: [], loading: false, error: '' },
      busy: false,
      panzoom: null,
      scale: 1,
      mini: { show: false, x: 0, y: 0, w: 100, h: 100 },
      moved: false,
      // reception
      customer: null,
      custQuery: '',
      custResults: [],
      custOpen: false,
      custBusy: false,
      override: false,
      overrideReason: '',
      notes: '',
      booked: null,

      // ------------------------------------------------------------ lifecycle
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.from = this.cfg.from;
        this.to = this.cfg.to;
        this.filter = this.cfg.type || '';
        this.customer = this.cfg.customer || null;
        const qs = new URLSearchParams(window.location.search);
        this.seatsNeeded = Math.min(this.cfg.maxSeats, Math.max(1, parseInt(qs.get('seats') || '1', 10) || 1));
        if (qs.get('view') === 'list') this.view = 'list';
        this.applyMap(this.cfg.initial);
        this.setSelection(this.cfg.initial.selection || null, false);
        this.$nextTick(() => {
          this.render();
          this.setupPanzoom();
          if (this.filter === 'CONF' && !this.selection) this.focusCategory('CONF');
        });
        this._tick = setInterval(() => this.tick(), 1000);
        this._poll = setInterval(() => { if (!document.hidden) this.poll(); }, this.cfg.pollSeconds * 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        window.addEventListener('resize', () => this.layoutStage());
        this.$watch('view', (v) => { if (v === 'map') this.$nextTick(() => this.layoutStage()); this.syncUrl(); });
        this.$watch('filter', () => { this.paint(); this.syncUrl(); });
        this.$watch('sheet', (v) => document.documentElement.classList.toggle('overflow-hidden', v && window.innerWidth < 1024));
      },

      applyMap(data) {
        this.map = data;
        this.byId = {};
        this.chairsOf = {};
        this.zonesById = {};
        for (const z of data.zones) this.zonesById[z.id] = z;
        for (const s of data.seats) {
          this.byId[s.id] = s;
          if (s.parent) (this.chairsOf[s.parent] ||= []).push(s.id);
        }
        this.units = data.seats.filter((s) => s.parent === null && s.category);
      },

      // ------------------------------------------------------------ getters
      get floor() { return this.map.floor; },
      get W() { return this.map.floor.width; },
      get H() { return this.map.floor.height; },
      get selUnits() { return this.selection?.units || []; },
      get selIds() { return this.selUnits.map((u) => u.id); },
      get selCategory() { return this.selection?.category || null; },
      get selPeriod() { return this.selection?.period || null; },
      get hourly() { return !!this.selPeriod?.start_time; },
      get chairCount() { return this.selUnits.reduce((n, u) => n + (u.capacity || 1), 0); },
      get multi() { return this.catMeta(this.selCategory || this.filter)?.multi ?? true; },
      get countdown() { const r = Math.max(0, this.remaining); return `${pad(Math.floor(r / 60))}:${pad(r % 60)}`; },
      get holdPct() { return Math.max(0, Math.min(100, (this.remaining / ((this.selection?.hold_minutes || this.cfg.holdMinutes) * 60)) * 100)); },
      get lowTime() { return this.selection && this.remaining > 0 && this.remaining <= 60; },
      get freeCount() { return this.units.filter((u) => (!this.filter || u.category === this.filter) && u.status === 'available').length; },
      get unitCount() { return this.units.filter((u) => !this.filter || u.category === this.filter).length; },
      get periodLabel() {
        const p = this.selPeriod;
        if (p?.start_time) return `${fmtDate(p.from)} · ${hourLabel(+p.start_time.slice(0, 2))} – ${hourLabel(+p.end_time.slice(0, 2))}`;
        const f = p?.from || this.from; const t = p?.to || this.to;
        return f === t ? fmtDate(f) : `${fmtShort(f)} → ${fmtDate(t)}`;
      },
      get included() {
        const seen = new Map();
        for (const u of this.selUnits) for (const f of this.byId[u.id]?.included || []) seen.set(f.code, f);
        if (!this.selUnits.length) for (const f of (this.units[0]?.included || [])) seen.set(f.code, f);
        return [...seen.values()];
      },
      get addonList() {
        const list = this.hourly ? (this.map.addons_hourly || []) : (this.map.addons || []);
        return list;
      },
      get listZones() {
        return this.map.zones.filter((z) => z.category).map((z) => ({
          ...z,
          items: this.units.filter((u) => u.zone === z.id),
          dim: this.filter && z.category !== this.filter,
        }));
      },
      catMeta(code) { return (this.cfg.categories || []).find((c) => c.code === code) || null; },
      catShort(code) { return this.catMeta(code)?.short || code; },
      statusLabel(s) { return STATUS[s] || s; },
      unitOf(id) { return this.byId[id]?.parent ?? id; },
      canPickMore() { return !this.multi || this.selIds.length < this.seatsNeeded; },
      floorHref(slug) {
        const q = new URLSearchParams({ from: this.from, to: this.to });
        if (this.filter) q.set('type', this.filter);
        if (this.seatsNeeded > 1) q.set('seats', this.seatsNeeded);
        if (this.cfg.staff && this.customer) q.set('customer', this.customer.id);
        const base = this.cfg.floorUrl.replace('__FLOOR__', slug);
        return base + (base.includes('?') ? '&' : '?') + q;
      },
      buildingHref() {
        const q = new URLSearchParams({ from: this.from, to: this.to });
        if (this.filter) q.set('type', this.filter);
        return `${this.cfg.buildingUrl}?${q}`;
      },

      // ------------------------------------------------------------ SVG rendering
      px(p) { return (p / 100) * this.W; },
      py(p) { return (p / 100) * this.H; },

      render() {
        const svg = this.$refs.svg;
        if (!svg) return;
        svg.setAttribute('viewBox', `0 0 ${this.W} ${this.H}`);
        svg.innerHTML = '';
        this.els = {};
        const defs = el('defs', {}, svg);
        const pat = el('pattern', { id: 'sx-stripes', width: 10, height: 10, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, defs);
        el('rect', { width: 5, height: 10, fill: 'rgba(255,255,255,.55)' }, pat);
        const pat2 = el('pattern', { id: 'sx-stripes-soft', width: 14, height: 14, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, defs);
        el('rect', { width: 14, height: 14, fill: 'rgba(254,226,226,.55)' }, pat2);
        el('rect', { width: 5, height: 14, fill: 'rgba(239,68,68,.16)' }, pat2);

        const zoneLayer = el('g', { class: 'sx-zones' }, svg);
        const unitLayer = el('g', { class: 'sx-units' }, svg);
        const seatLayer = el('g', { class: 'sx-seats' }, svg);
        const facLayer = el('g', { class: 'sx-facs' }, svg);
        const tagLayer = el('g', { class: 'sx-tags' }, svg);

        // Zones: soft tint + price tag (not for whole-unit zones — those get a unit tag)
        for (const z of this.map.zones) {
          if (!z.category) continue;
          const colour = z.colour || '#94a3b8';
          const poly = el('polygon', {
            class: 'sx-zone', 'data-zone': z.id,
            points: z.polygon.map(([x, y]) => `${this.px(x)},${this.py(y)}`).join(' '),
            fill: colour, stroke: colour,
          }, zoneLayer);
          this.els[`z${z.id}`] = poly;
          const meta = this.catMeta(z.category);
          if (meta?.whole_unit) continue;
          const first = this.units.find((u) => u.zone === z.id);
          const title = `${meta?.short || ''} · from ${fromPrice(first?.rates)}`;
          const sub = `${z.free} of ${z.units} free`;
          const w = Math.max(title.length * 11.6, sub.length * 9.6) + 64;
          // pill straddling the zone's top wall, centred (the plan's own zone label sits top-left)
          const x = this.px(z.rect.x + z.rect.w / 2) - w / 2;
          const y = Math.max(4, this.py(z.rect.y) - 34);
          const tag = el('g', { class: 'sx-zone-tag', transform: `translate(${x},${y})`, 'data-ztag': z.id }, tagLayer);
          el('rect', { width: w, height: 64, rx: 32 }, tag);
          el('circle', { class: 'dot', cx: 32, cy: 32, r: 9, fill: colour }, tag);
          el('text', { x: 52, y: 29 }, tag).textContent = title;
          const t2 = el('text', { x: 52, y: 51, class: 'sub' }, tag);
          t2.textContent = sub;
          this.els[`zt${z.id}`] = t2;
        }

        // Whole units (cabin / conference): outline + tag, clickable as one block
        for (const u of this.units.filter((s) => s.kind !== 'seat')) {
          const g = el('g', {
            class: 'sx-unit', 'data-id': u.id, role: 'button', tabindex: -1,
            'data-status': u.status,
          }, unitLayer);
          el('rect', { class: 'outline', x: this.px(u.x), y: this.py(u.y), width: this.px(u.w), height: this.py(u.h), rx: 18 }, g);
          const meta = this.catMeta(u.category);
          const line1 = u.label;
          const line2 = meta?.hourly ? `${fromPrice(u.rates)} · pick a slot` : fromPrice(u.rates);
          const tw = Math.min(this.px(u.w) - 12, Math.max(line1.length * 11.5, line2.length * 8.6) + 56);
          const tx = this.px(u.x + u.w / 2) - tw / 2;
          // cabins: inside, near the bottom; the conference room: straddling its bottom wall (chairs fill the room)
          const ty = meta?.hourly ? this.py(u.y + u.h) - 22 : this.py(u.y + u.h) - 70;
          const tag = el('g', { class: 'tag', transform: `translate(${tx},${ty})` }, g);
          el('rect', { width: tw, height: 58, rx: 18 }, tag);
          glyph(meta?.hourly ? 'clock' : 'door-open', { x: 12, y: 17, width: 24, height: 24, style: 'color:#fff' }, tag);
          el('text', { x: 44, y: 26 }, tag).textContent = line1;
          el('text', { x: 44, y: 46, class: 'sub' }, tag).textContent = line2;
          this.bindUnit(g, u.id);
          this.els[u.id] = g;
          for (const cid of this.chairsOf[u.id] || []) this.drawChair(seatLayer, this.byId[cid], true);
        }
        // Individual seats
        for (const u of this.units.filter((s) => s.kind === 'seat')) this.drawChair(seatLayer, u, false);

        // Facilities & landmarks
        for (const f of this.map.facilities) {
          const g = el('g', { class: 'sx-fac', 'data-kind': f.kind, transform: `translate(${this.px(f.x)},${this.py(f.y)})`, tabindex: -1 }, facLayer);
          el('circle', { r: 19 }, g);
          glyph(f.icon || 'info', { x: -11, y: -11, width: 22, height: 22 }, g);
          if (f.kind === 'addon') el('circle', { class: 'plus', cx: 14, cy: -14, r: 6 }, g);
          el('title', {}, g).textContent = f.name;
          g.addEventListener('pointerenter', () => this.showFacTip(f, g));
          g.addEventListener('pointerleave', () => this.hideTip());
          g.addEventListener('click', (e) => { e.stopPropagation(); this.showFacTip(f, g); });
        }
        this.paint();
        this.rovingInit();
      },

      drawChair(layer, s, child) {
        const x = this.px(s.x); const y = this.py(s.y); const w = this.px(s.w); const h = this.py(s.h);
        const g = el('g', {
          class: 'sx-seat', 'data-id': s.id, transform: `translate(${x},${y})`,
          ...(child ? { 'aria-hidden': 'true' } : { role: 'button', tabindex: -1 }),
        }, layer);
        const pop = el('g', { class: 'pop' }, g);
        el('rect', { class: 'ring', x: -5, y: -5, width: w + 10, height: h + 10, rx: 16 }, pop);
        el('rect', { class: 'body', width: w, height: h, rx: Math.min(14, w * 0.28) }, pop);
        el('rect', { class: 'stripes', width: w, height: h, rx: Math.min(14, w * 0.28) }, pop);
        const small = w < 48;
        const gs = small ? w * 0.56 : w * 0.46;
        glyph('armchair', { class: 'glyph', x: (w - gs) / 2, y: small ? (h - gs) / 2 : h * 0.14, width: gs, height: gs }, pop);
        if (!small) el('text', { class: 'num', x: w / 2, y: h - 8 }, pop).textContent = s.label;
        this.els[s.id] = g;
        if (!child) this.bindUnit(g, s.id);
        return g;
      },

      bindUnit(g, id) {
        let timer = null;
        let longPressed = false;
        g.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') this.showTip(id); });
        g.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') this.hideTip(); });
        g.addEventListener('pointerdown', (e) => {
          this.moved = false;
          longPressed = false;
          if (e.pointerType !== 'mouse') timer = setTimeout(() => { longPressed = true; this.showTip(id); }, 450);
        });
        const cancel = () => { clearTimeout(timer); };
        g.addEventListener('pointerup', cancel);
        g.addEventListener('pointercancel', cancel);
        g.addEventListener('contextmenu', (e) => e.preventDefault());
        g.addEventListener('click', (e) => {
          e.stopPropagation();
          if (this.moved || longPressed) { longPressed = false; return; }
          this.activate(id);
        });
        g.addEventListener('keydown', (e) => this.onKey(e, id));
        g.addEventListener('focus', () => { g.classList.add('is-focus'); this.showTip(id); });
        g.addEventListener('blur', () => { g.classList.remove('is-focus'); this.hideTip(); });
      },

      /** Update colours, glyphs, aria and dimming from byId statuses. */
      paint() {
        for (const s of this.map.seats) {
          const g = this.els[s.id];
          if (!g) continue;
          const unitId = s.parent ?? s.id;
          const unit = this.byId[unitId];
          const status = this.pending[unitId] ? 'mine' : s.status;
          if (g.dataset.status !== status) g.dataset.status = status;
          g.classList.toggle('is-pending', !!this.pending[unitId]);
          const dim = !!this.filter && unit.category !== this.filter;
          g.classList.toggle('is-dim', dim);
          g.classList.toggle('is-override', this.override && ['held', 'blocked'].includes(status));
          const glyphEl = g.querySelector('.glyph');
          if (glyphEl) glyphEl.setAttribute('href', `#i-${GLYPH[status] || 'armchair'}`);
          if (s.parent === null) {
            const meta = this.catMeta(s.category);
            g.setAttribute('aria-pressed', status === 'mine' ? 'true' : 'false');
            g.setAttribute('aria-disabled', ['available', 'mine'].includes(status) ? 'false' : 'true');
            g.setAttribute('aria-label', `${s.kind === 'seat' ? 'Seat ' : ''}${s.label.length <= 3 ? s.code : s.label}, ${meta?.short || ''}, ${STATUS[status]}, ${priceText(s.rates)}`);
          }
        }
        for (const z of this.map.zones) {
          this.els[`z${z.id}`]?.classList.toggle('is-dim', !!this.filter && z.category !== this.filter);
          const t = this.els[`zt${z.id}`];
          if (t) {
            const units = this.units.filter((u) => u.zone === z.id);
            t.textContent = `${units.filter((u) => u.status === 'available').length} of ${units.length} free`;
          }
        }
      },

      flash(id) {
        const g = this.els[id];
        if (!g) return;
        g.classList.remove('is-flash');
        void g.getBoundingClientRect();
        g.classList.add('is-flash');
        setTimeout(() => g.classList.remove('is-flash'), 1000);
        for (const c of this.chairsOf[id] || []) this.flash(c);
      },

      // ------------------------------------------------------------ keyboard (roving tabindex per zone)
      rovingInit() {
        const seen = new Set();
        for (const u of this.units) {
          const g = this.els[u.id];
          if (!g) continue;
          g.setAttribute('tabindex', seen.has(u.zone) ? '-1' : '0');
          seen.add(u.zone);
        }
      },
      onKey(e, id) {
        const u = this.byId[id];
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.activate(id); return; }
        const dirs = { ArrowRight: [1, 0], ArrowLeft: [-1, 0], ArrowDown: [0, 1], ArrowUp: [0, -1] };
        const d = dirs[e.key];
        if (!d) return;
        e.preventDefault();
        const cx = u.x + u.w / 2; const cy = u.y + u.h / 2;
        let best = null; let bestScore = Infinity;
        for (const o of this.units) {
          if (o.id === id || o.zone !== u.zone) continue;
          const dx = o.x + o.w / 2 - cx; const dy = o.y + o.h / 2 - cy;
          const along = dx * d[0] + dy * d[1];
          if (along <= 0.2) continue;
          const across = Math.abs(dx * d[1]) + Math.abs(dy * d[0]);
          const score = along + across * 3;
          if (score < bestScore) { bestScore = score; best = o; }
        }
        if (best) {
          this.els[id].setAttribute('tabindex', '-1');
          const g = this.els[best.id];
          g.setAttribute('tabindex', '0');
          g.focus();
          this.ensureVisible(best.id);
        }
      },

      // ------------------------------------------------------------ tooltip
      showTip(id) {
        const g = this.els[id];
        const u = this.byId[id];
        if (!g || !u) return;
        const r = g.getBoundingClientRect();
        const below = r.top < 190;
        this.tip = { show: true, x: Math.min(window.innerWidth - 150, Math.max(150, r.left + r.width / 2)), y: below ? r.bottom + 12 : r.top - 12, below, id, fac: null };
      },
      showFacTip(f, g) {
        const r = g.getBoundingClientRect();
        const below = r.top < 150;
        this.tip = { show: true, x: Math.min(window.innerWidth - 150, Math.max(150, r.left + r.width / 2)), y: below ? r.bottom + 10 : r.top - 10, below, id: null, fac: f };
      },
      hideTip() { this.tip.show = false; },
      get tipSeat() { return this.tip.id ? this.byId[this.tip.id] : null; },
      tipStatus() { const s = this.tipSeat; return s ? (this.pending[s.id] ? 'mine' : s.status) : ''; },
      nearHint(u) {
        if (!u) return '';
        const hints = [];
        if (u.tags?.includes('window')) hints.push('Window side');
        let best = null; let bd = Infinity;
        const cx = u.x + u.w / 2; const cy = u.y + u.h / 2;
        for (const f of this.map.facilities) {
          if (!['PANTRY', 'RESTROOM', 'ENTRY', 'LIFT', 'PRINTING'].includes(f.code)) continue;
          const d = Math.hypot((f.x - cx) * 1.6, f.y - cy);
          if (d < bd) { bd = d; best = f; }
        }
        if (best && bd < 34) hints.push(`near ${best.name.toLowerCase().replace(' access', '')}`);
        return hints.join(' · ').replace(/^near/, 'Near');
      },

      // ------------------------------------------------------------ selecting
      activate(id) {
        const unitId = this.unitOf(id);
        const u = this.byId[unitId];
        if (!u) return;
        this.hideTip();
        if (!this.cfg.loggedIn) { this.signIn = true; return; }
        if (this.cfg.staff && !this.cfg.canBook) { this.toast('Your role can view the map but not hold seats.', 'info'); return; }
        const status = this.pending[unitId] ? 'mine' : u.status;
        if (status === 'mine') { this.release(unitId); return; }
        const overridable = this.override && ['held', 'blocked'].includes(status);
        if (status !== 'available' && !overridable) {
          this.shake(unitId);
          const msg = {
            occupied: `${u.label.length <= 3 ? u.code : u.label} is booked for these dates.`,
            held: `${u.code} is being held by someone right now — try again in a few minutes.`,
            blocked: `${u.code} is unavailable (blocked or under maintenance).`,
          }[status];
          this.toast(msg, 'warning');
          return;
        }
        if (this.override && overridable && this.overrideReason.trim().length < 5) {
          this.toast('Enter an override reason first (at least 5 characters).', 'warning');
          this.$refs.overrideReason?.focus();
          return;
        }
        const meta = this.catMeta(u.category);
        if (this.selCategory && this.selCategory !== u.category) { this.switchTo = unitId; return; }
        if (meta?.hourly) { this.openSlots(unitId); return; }
        if (meta?.whole_unit && this.selIds.length) { this.switchTo = unitId; return; }
        if (meta?.multi && this.selIds.length >= this.seatsNeeded) {
          if (this.seatsNeeded < this.cfg.maxSeats) {
            this.toast(`You asked for ${this.seatsNeeded} seat${this.seatsNeeded === 1 ? '' : 's'}.`, 'info', { label: `Make it ${this.seatsNeeded + 1}`, fn: () => { this.seatsNeeded++; this.activate(unitId); } });
          } else this.toast(`At most ${this.cfg.maxSeats} seats per request.`, 'warning');
          return;
        }
        this.hold([unitId]);
      },

      async confirmSwitch() {
        const id = this.switchTo;
        this.switchTo = null;
        await this.clearAll(true);
        this.activate(id);
      },

      shake(id) {
        const g = this.els[id];
        if (!g) return;
        g.animate([{ transform: `${g.getAttribute('transform') || ''} translate(0,0)` }, { transform: `${g.getAttribute('transform') || ''} translate(-5px,0)` }, { transform: `${g.getAttribute('transform') || ''} translate(5px,0)` }, { transform: `${g.getAttribute('transform') || ''} translate(0,0)` }], { duration: 260 });
      },

      periodParams(p = null) {
        if (p) return p;
        return { from: this.from, to: this.to };
      },

      async hold(ids, { period = null, replace = false, quiet = false } = {}) {
        for (const id of ids) this.pending[id] = true;
        this.paint();
        try {
          const res = await this.api('POST', '/holds', {
            seat_ids: ids, ...this.periodParams(period), seats_needed: this.multi ? this.seatsNeeded : 1, replace,
            customer_id: this.customer?.id || null,
            override_reason: this.override ? this.overrideReason : null,
          });
          for (const [id, msg] of Object.entries(res.failed || {})) {
            if (!quiet) this.toast(msg, 'warning');
            const s = this.byId[id];
            if (s && s.status === 'available') s.status = msg.includes('booked') ? 'occupied' : 'held';
            this.flash(+id);
          }
          this.setSelection(res.selection);
          if (!quiet && res.held?.length && window.innerWidth < 1024 && this.selIds.length === 1) this.nudgeSheet();
          return res;
        } catch (e) {
          this.handleError(e);
          return null;
        } finally {
          for (const id of ids) delete this.pending[id];
          this.paint();
        }
      },

      async release(id) {
        const was = this.byId[id]?.status;
        if (this.byId[id]) this.byId[id].status = 'available';
        this.paint();
        try {
          const res = await this.api('DELETE', `/holds/${id}`, { customer_id: this.customer?.id || null });
          this.setSelection(res.selection);
        } catch (e) {
          if (this.byId[id]) this.byId[id].status = was;
          this.handleError(e);
        }
      },

      async clearAll(quiet = false) {
        try {
          const res = await this.api('DELETE', '/holds', {});
          this.setSelection(res.selection);
          if (!quiet) this.toast('Selection cleared.', 'info');
        } catch (e) { this.handleError(e); }
      },

      async renew() {
        try {
          const res = await this.api('POST', '/holds/renew', {});
          this.setSelection(res.selection);
          this.toast(`Held for another ${this.cfg.holdMinutes} minutes.`, 'success');
        } catch (e) { this.handleError(e); this.poll(); }
      },

      /** Adopt the server's view of my selection (units, period, countdown). */
      setSelection(sel, requote = true) {
        const before = new Set(this.selIds);
        this.selection = sel && sel.units?.length ? sel : null;
        const now = new Set(this.selIds);
        for (const id of before) if (!now.has(id) && this.byId[id]?.status === 'mine') this.byId[id].status = 'available';
        for (const id of now) {
          if (!this.byId[id]) continue;
          this.byId[id].status = 'mine';
          for (const c of this.chairsOf[id] || []) this.byId[c].status = 'mine';
        }
        for (const id of before) if (!now.has(id)) for (const c of this.chairsOf[id] || []) if (this.byId[c].status === 'mine') this.byId[c].status = 'available';
        this.deadline = this.selection ? Date.now() + this.selection.expires_in * 1000 : 0;
        this.remaining = this.selection ? this.selection.expires_in : 0;
        if (this.selection && this.multi && this.selIds.length > this.seatsNeeded) this.seatsNeeded = this.selIds.length;
        if (!this.selection) { this.quote = null; this.sheet = false; }
        // drop add-ons that don't fit the period type
        const valid = new Set(this.addonList.map((a) => String(a.id)));
        for (const k of Object.keys(this.addons)) if (!valid.has(k)) delete this.addons[k];
        this.paint();
        if (requote) this.refreshQuote();
        else if (this.selection) this.refreshQuote();
      },

      tick() {
        if (!this.selection) return;
        this.remaining = Math.max(0, Math.round((this.deadline - Date.now()) / 1000));
        if (this.remaining === 0) {
          const codes = this.selUnits.map((u) => u.code).join(', ');
          this.setSelection(null);
          this.toast(`Your ${this.cfg.holdMinutes}-minute hold on ${codes} expired — the seats were released.`, 'warning');
          this.poll(true);
        }
      },

      nudgeSheet() {
        const bar = this.$refs.sheetBar;
        if (bar) bar.animate([{ transform: 'translateY(12px)' }, { transform: 'translateY(-6px)' }, { transform: 'translateY(0)' }], { duration: 420, easing: 'cubic-bezier(.34,1.56,.64,1)' });
      },

      // ------------------------------------------------------------ auto-pick adjacent seats
      autoPick() {
        const cat = this.selCategory || (this.catMeta(this.filter)?.multi ? this.filter : 'DEDICATED');
        const need = this.seatsNeeded - this.selIds.length;
        if (need <= 0) { this.toast('You already have all the seats you asked for.', 'info'); return; }
        const pool = this.units.filter((u) => u.category === cat && u.kind === 'seat');
        const sel = this.selUnits.map((s) => this.byId[s.id]).filter(Boolean);
        const centre = sel.length ? { x: sel.reduce((a, s) => a + s.x, 0) / sel.length, y: sel.reduce((a, s) => a + s.y, 0) / sel.length } : null;
        const rows = new Map();
        for (const u of pool) {
          const key = `${u.zone}:${Math.round(u.y)}`;
          if (!rows.has(key)) rows.set(key, []);
          rows.get(key).push(u);
        }
        let best = null; let bestScore = Infinity;
        for (const row of rows.values()) {
          row.sort((a, b) => a.x - b.x);
          for (let i = 0; i + need <= row.length; i++) {
            const run = row.slice(i, i + need);
            if (!run.every((u) => u.status === 'available')) continue;
            const mx = run.reduce((a, u) => a + u.x, 0) / need;
            const score = centre ? Math.hypot(mx - centre.x, run[0].y - centre.y) : run[0].y * 10 + mx;
            if (score < bestScore) { bestScore = score; best = run; }
          }
        }
        if (!best) {
          const free = pool.filter((u) => u.status === 'available');
          if (free.length < need) { this.toast(`Only ${free.length} ${this.catShort(cat)} seats are free for these dates.`, 'warning'); return; }
          const c = centre || free[0];
          best = free.sort((a, b) => Math.hypot(a.x - c.x, a.y - c.y) - Math.hypot(b.x - c.x, b.y - c.y)).slice(0, need);
          this.toast(`No ${need} seats in a row — picked the closest free seats instead.`, 'info');
        }
        if (this.filter !== cat) this.filter = cat;
        this.hold(best.map((u) => u.id)).then((res) => { if (res?.held?.length) this.ensureVisible(best[0].id); });
      },

      // ------------------------------------------------------------ conference slot picker
      openSlots(unitId) {
        const p = this.selPeriod;
        this.slot = {
          open: true, unit: unitId, date: p?.start_time ? p.from : (this.from < this.cfg.today ? this.cfg.today : this.from),
          start: p?.start_time ? +p.start_time.slice(0, 2) : null, end: p?.end_time ? +p.end_time.slice(0, 2) : null,
          slots: this.map.slots?.[unitId] || [], loading: false, error: '',
        };
        if (this.slot.date !== this.map.period.from) this.loadSlots();
      },
      async loadSlots() {
        this.slot.loading = true;
        this.slot.start = null; this.slot.end = null;
        try {
          const q = new URLSearchParams({ floor: this.floor.slug, from: this.slot.date, to: this.slot.date });
          if (this.customer) q.set('customer_id', this.customer.id);
          const res = await window.Commune.request(`${this.cfg.api}/availability?${q}`);
          this.slot.slots = res.slots?.[this.slot.unit] || [];
        } catch (e) { this.slot.error = 'Could not load the booked slots.'; } finally { this.slot.loading = false; }
      },
      get hours() { const out = []; for (let h = this.cfg.openHour; h < this.cfg.closeHour; h++) out.push(h); return out; },
      slotAt(h) {
        return (this.slot.slots || []).find((s) => +s.start.slice(0, 2) <= h && (+s.end.slice(0, 2) + (s.end.slice(3, 5) !== '00' ? 1 : 0)) > h) || null;
      },
      slotState(h) {
        const now = new Date();
        if (this.slot.date === this.cfg.today && h <= now.getHours()) return 'past';
        const s = this.slotAt(h);
        if (s && s.status === 'occupied') return 'booked';
        if (s && s.status === 'held') return 'held';
        if (this.slot.start !== null && h >= this.slot.start && h < this.slot.end) return (h === this.slot.start || h === this.slot.end - 1) ? 'edge' : 'range';
        return 'free';
      },
      pickHour(h) {
        const st = this.slotState(h);
        if (['past', 'booked', 'held'].includes(st)) return;
        if (this.slot.start === null || this.slot.end - this.slot.start > 1 || h < this.slot.start) {
          this.slot.start = h; this.slot.end = h + 1; return;
        }
        // extend a single-hour pick to a range, if nothing booked in between
        for (let x = this.slot.start; x <= h; x++) {
          if (['booked', 'held', 'past'].includes(this.slotState(x)) && x !== this.slot.start) { this.slot.start = h; this.slot.end = h + 1; this.toast('That range crosses a booked slot — starting a new range.', 'info'); return; }
        }
        this.slot.end = h + 1;
      },
      get slotHours() { return this.slot.start === null ? 0 : this.slot.end - this.slot.start; },
      get slotPrice() { const r = this.byId[this.slot.unit]?.rates?.hour || 0; return r * this.slotHours; },
      async confirmSlot() {
        if (this.slot.start === null) return;
        const period = { from: this.slot.date, to: this.slot.date, start_time: `${pad(this.slot.start)}:00`, end_time: `${pad(this.slot.end)}:00` };
        this.slot.open = false;
        const res = await this.hold([this.slot.unit], { period, replace: true });
        if (res?.held?.length) this.toast(`Conference room held · ${this.periodLabel}`, 'success');
      },

      // ------------------------------------------------------------ add-ons & quote
      addonOn(a) { return (this.addons[a.id] || 0) > 0; },
      addonMax(a) {
        const cap = a.unit === 'use' ? 10 : Math.max(1, this.selUnits.length === 1 ? this.chairCount : this.selUnits.length);
        return a.left === null ? cap : Math.min(cap, a.left);
      },
      toggleAddon(a) {
        if (this.addonOn(a)) delete this.addons[a.id];
        else if (a.left === 0) { this.toast(`${a.name} is sold out for these dates.`, 'warning'); return; }
        else this.addons[a.id] = 1;
        this.refreshQuote();
      },
      stepAddon(a, d) {
        const q = Math.max(0, Math.min(this.addonMax(a), (this.addons[a.id] || 0) + d));
        if (q === 0) delete this.addons[a.id]; else this.addons[a.id] = q;
        this.refreshQuote();
      },
      addonPrice(a) { return `${money(a.price)}/${a.unit === 'use' ? 'pack' : UNIT_SHORT[a.unit] || a.unit}`; },
      refreshQuote() {
        clearTimeout(this._q);
        if (!this.selection) { this.quote = null; return; }
        this.quoteBusy = true;
        this._q = setTimeout(async () => {
          try {
            const p = this.selPeriod;
            const res = await this.api('POST', '/quote', { ...p, addons: this.addons, customer_id: this.customer?.id || null });
            this.quote = res.quote;
            this.quoteError = '';
          } catch (e) {
            this.quoteError = e.body?.message || 'Could not price this selection.';
            if (e.status === 422 && /add-on|sold out|left/.test(this.quoteError)) { this.toast(this.quoteError, 'warning'); this.addons = {}; this.refreshQuote(); }
          } finally { this.quoteBusy = false; }
        }, 220);
      },
      get addonsJson() { return JSON.stringify(this.addons); },
      get backUrl() { return window.location.pathname + window.location.search; },
      checkout() {
        if (!this.selection) return;
        this.$refs.checkoutForm?.submit();
      },

      // ------------------------------------------------------------ dates, filters, polling
      datesChanged() {
        if (this.from < this.cfg.today) this.from = this.cfg.today;
        if (!this.to || this.to < this.from) this.to = this.from;
        this.syncUrl();
        this.reload(true);
      },
      syncUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('from', this.from); url.searchParams.set('to', this.to);
        if (this.filter) url.searchParams.set('type', this.filter); else url.searchParams.delete('type');
        if (this.seatsNeeded > 1) url.searchParams.set('seats', this.seatsNeeded); else url.searchParams.delete('seats');
        if (this.view === 'list') url.searchParams.set('view', 'list'); else url.searchParams.delete('view');
        if (this.cfg.staff && this.customer) url.searchParams.set('customer', this.customer.id);
        window.history.replaceState(null, '', url);
      },
      async reload(rehold = false) {
        this.loading = true;
        try {
          const q = new URLSearchParams({ from: this.from, to: this.to });
          if (this.customer) q.set('customer_id', this.customer.id);
          const data = await window.Commune.request(`${this.cfg.api}/floors/${this.floor.slug}/map?${q}`);
          const keep = this.selection;
          this.applyMap(data);
          this.render();
          this.setSelection(data.selection || null);
          if (rehold && keep && !keep.period?.start_time && (keep.period.from !== this.from || keep.period.to !== this.to)) {
            const ids = keep.units.map((u) => u.id);
            const res = await this.hold(ids, { replace: true, quiet: true });
            const lost = Object.keys(res?.failed || {}).length;
            if (res) this.toast(lost ? `${lost} of your seats aren't free on the new dates and were removed.` : 'Your seats are held for the new dates.', lost ? 'warning' : 'success');
          }
        } catch (e) { this.handleError(e); } finally { this.loading = false; }
      },
      async poll(force = false) {
        try {
          const q = new URLSearchParams({ floor: this.floor.slug, from: this.from, to: this.to });
          if (!force) q.set('version', this.map.version);
          if (this.customer) q.set('customer_id', this.customer.id);
          const res = await window.Commune.request(`${this.cfg.api}/availability?${q}`);
          if (res.unchanged) return;
          this.map.version = res.version;
          this.map.slots = res.slots || this.map.slots;
          const mineBefore = new Set(this.selIds);
          for (const [id, status] of Object.entries(res.statuses || {})) {
            const s = this.byId[id];
            if (!s || this.pending[this.unitOf(+id)]) continue;
            if (s.status !== status) {
              if (['occupied', 'held'].includes(status) && s.parent === null && s.status === 'available') this.flash(+id);
              s.status = status;
            }
          }
          const sel = res.selection || null;
          const now = new Set((sel?.units || []).map((u) => u.id));
          const lost = [...mineBefore].filter((id) => !now.has(id));
          if (lost.length && sel !== null) this.toast(`${lost.map((id) => this.byId[id]?.code).join(', ')} is no longer held for you and was removed.`, 'warning');
          else if (lost.length && this.remaining > 5) this.toast('Your selection was released.', 'warning');
          this.setSelection(sel, lost.length > 0);
        } catch (e) { /* offline — try again next round */ }
      },

      // ------------------------------------------------------------ pan & zoom
      setupPanzoom() {
        this.layoutStage();
        const stage = this.$refs.stage;
        if (!stage || !window.Panzoom) return;
        this.panzoom = window.Panzoom(stage, { maxScale: 4, minScale: 1, contain: 'outside', step: 0.4, animate: true, duration: 220, cursor: 'grab' });
        let start = null;
        stage.addEventListener('pointerdown', (e) => { start = { x: e.clientX, y: e.clientY }; this.moved = false; });
        stage.addEventListener('pointermove', (e) => { if (start && Math.hypot(e.clientX - start.x, e.clientY - start.y) > 6) this.moved = true; });
        stage.addEventListener('pointerup', () => { start = null; });
        stage.addEventListener('panzoomchange', () => { this.scale = this.panzoom.getScale(); this.updateMini(); this.hideTip(); });
        this.$refs.viewport.addEventListener('wheel', (e) => {
          if (!e.ctrlKey && !e.metaKey) return;
          e.preventDefault();
          this.panzoom.zoomWithWheel(e);
        }, { passive: false });
        this.$refs.viewport.addEventListener('click', () => this.hideTip());
        this.resetZoom(false);
      },
      layoutStage() {
        const vp = this.$refs.viewport; const stage = this.$refs.stage;
        if (!vp || !stage || !vp.clientWidth) return;
        const aspect = this.W / this.H;
        const mobile = window.innerWidth < 768;
        const vw = vp.clientWidth;
        let vh = mobile ? Math.max(340, Math.round(window.innerHeight * 0.58)) : Math.round(vw / aspect);
        if (!mobile) vh = Math.min(vh, Math.round(window.innerHeight * 0.78));
        vp.style.height = `${vh}px`;
        let sw = vw; let sh = vw / aspect;
        if (sh < vh) { sh = vh; sw = vh * aspect; }
        stage.style.width = `${sw}px`;
        stage.style.height = `${sh}px`;
        this.updateMini();
      },
      zoomIn() { this.panzoom?.zoomIn(); },
      zoomOut() { this.panzoom?.zoomOut(); },
      resetZoom(animate = true) {
        if (!this.panzoom) return;
        this.panzoom.reset({ animate });
        const vp = this.$refs.viewport; const stage = this.$refs.stage;
        const extra = stage.offsetWidth - vp.clientWidth;
        if (extra > 0) {
          // mobile: start on the chosen category (or the left side of the plan)
          const z = this.map.zones.find((zz) => zz.category && zz.category === this.filter) || this.map.zones.find((zz) => zz.category);
          const cx = z ? this.px(z.rect.x + z.rect.w / 2) * (stage.offsetWidth / this.W) : 0;
          const x = Math.max(-extra, Math.min(0, vp.clientWidth / 2 - cx));
          setTimeout(() => this.panzoom.pan(x, 0, { animate }), 10);
        }
        this.updateMini();
      },
      focusCategory(code) {
        const z = this.map.zones.find((zz) => zz.category === code);
        if (z) this.ensureVisible(this.units.find((u) => u.zone === z.id)?.id);
      },
      ensureVisible(id) {
        if (!this.panzoom || !id) return;
        const g = this.els[id]; const vp = this.$refs.viewport;
        if (!g || !vp) return;
        const r = g.getBoundingClientRect(); const v = vp.getBoundingClientRect();
        if (r.left >= v.left && r.right <= v.right && r.top >= v.top && r.bottom <= v.bottom) return;
        const pan = this.panzoom.getPan(); const s = this.panzoom.getScale();
        const dx = (v.left + v.width / 2 - (r.left + r.width / 2)) / s;
        const dy = (v.top + v.height / 2 - (r.top + r.height / 2)) / s;
        this.panzoom.pan(pan.x + dx, pan.y + dy, { animate: true });
      },
      updateMini() {
        const vp = this.$refs.viewport; const stage = this.$refs.stage;
        if (!vp || !stage || !this.panzoom) { this.mini.show = false; return; }
        // Panzoom: screen = O + s·(P − O + pan), O = stage centre  ⇒  P(viewport centre) = O + (V − O)/s − pan
        const s = this.panzoom.getScale(); const p = this.panzoom.getPan();
        const sw = stage.offsetWidth; const sh = stage.offsetHeight;
        const vw = vp.clientWidth / s; const vh = vp.clientHeight / s;
        const vcx = sw / 2 + (vp.clientWidth / 2 - sw / 2) / s - p.x;
        const vcy = sh / 2 + (vp.clientHeight / 2 - sh / 2) / s - p.y;
        this.mini = {
          show: s > 1.05 || sw > vp.clientWidth + 4,
          x: Math.max(0, ((vcx - vw / 2) / sw) * 100),
          y: Math.max(0, ((vcy - vh / 2) / sh) * 100),
          w: Math.min(100, (vw / sw) * 100),
          h: Math.min(100, (vh / sh) * 100),
        };
      },
      miniJump(e) {
        const r = e.currentTarget.getBoundingClientRect();
        const fx = (e.clientX - r.left) / r.width; const fy = (e.clientY - r.top) / r.height;
        const stage = this.$refs.stage; const vp = this.$refs.viewport; const s = this.panzoom.getScale();
        const sw = stage.offsetWidth; const sh = stage.offsetHeight;
        const x = (vp.clientWidth / 2 - sw / 2) / s + sw / 2 - fx * sw;
        const y = (vp.clientHeight / 2 - sh / 2) / s + sh / 2 - fy * sh;
        this.panzoom.pan(x, y, { animate: true });
      },

      // ------------------------------------------------------------ reception
      searchCustomers() {
        clearTimeout(this._cs);
        const q = this.custQuery.trim();
        if (q.length < 2) { this.custResults = []; return; }
        this._cs = setTimeout(async () => {
          this.custBusy = true;
          try {
            const res = await window.Commune.request(`${this.cfg.customersUrl}?q=${encodeURIComponent(q)}`);
            this.custResults = res.results;
            this.custOpen = true;
          } catch (e) { this.custResults = []; } finally { this.custBusy = false; }
        }, 250);
      },
      pickCustomer(c) {
        this.customer = c;
        this.custQuery = '';
        this.custResults = [];
        this.custOpen = false;
        this.syncUrl();
        this.refreshQuote();
        this.toast(`Booking for ${c.name}${c.unique_id ? ` (${c.unique_id})` : ''}.`, 'success');
      },
      kycTone(s) { return { verified: 'badge-success', pending: 'badge-warning', rejected: 'badge-danger' }[s] || 'badge-neutral'; },
      kycLabel(s) { return { verified: 'KYC verified', pending: 'KYC pending', rejected: 'KYC rejected', not_submitted: 'KYC not submitted' }[s] || s; },
      async bookNow() {
        if (!this.customer) { this.toast('Pick the visitor first (search by Unique ID, name or mobile).', 'warning'); this.$refs.custInput?.focus(); return; }
        if (!this.selection) return;
        this.busy = true;
        try {
          const res = await this.api('POST', '/book', {
            customer_id: this.customer.id, addons: this.addons, notes: this.notes,
            override_reason: this.override ? this.overrideReason : null,
          });
          this.booked = res;
          this.addons = {};
          this.notes = '';
          this.setSelection(null);
          this.poll(true);
        } catch (e) { this.handleError(e); } finally { this.busy = false; }
      },

      // ------------------------------------------------------------ plumbing
      api(method, path, data) {
        return window.Commune.request(`${this.cfg.api}${path}`, { method, data });
      },
      handleError(e) {
        if (e.status === 401) { this.signIn = true; return; }
        if (e.status === 419) { this.toast('Your session expired — reloading…', 'warning'); setTimeout(() => window.location.reload(), 1200); return; }
        this.toast(e.body?.message || e.message || 'Something went wrong.', e.status === 422 ? 'warning' : 'danger');
      },
      toast(msg, tone = 'info', action = null) {
        const id = Math.random().toString(36).slice(2);
        this.toasts.push({ id, msg, tone, action });
        if (this.toasts.length > 3) this.toasts.shift();
        setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, action ? 7000 : 4500);
      },
      runToast(t) { this.toasts = this.toasts.filter((x) => x.id !== t.id); t.action?.fn(); },
      loginHref(base) { return `${base}?next=${encodeURIComponent(window.location.pathname + window.location.search)}`; },
      money,
      fmtDate,
      hourLabel,
      priceText,
    }));
  });
})();
