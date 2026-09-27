/**
 * Layout & Pricing Designer (spec 5.4) — Figma-lite editor for a floor's seats, zones and facilities, plus the
 * building hotspot editor. Source: resources/js/designer.js → public/assets/js/designer.js (npm run vendor:js).
 * Load (defer) after panzoom.min.js and space-render.js (shared SVG drawing with the Space Explorer), before Alpine.
 *
 * Alpine components
 *   layoutDesigner   staff/layout/designer — canvas editor on a DRAFT layout version
 *   hotspotEditor    staff/layout/building — floor polygons on the building photo
 *
 * Document model (same shape as LayoutDraftService::document()): { zones, seats, placements } with geometry in
 * PERCENT of the floor image. New items get temporary ids "t123"; the autosave response maps them to row ids.
 *
 * JSON API (LayoutApiController, /staff/layout/api):
 *   POST floors/{id}/draft · GET|PUT|DELETE versions/{v} · GET versions/{v}/check · POST versions/{v}/publish
 *   GET versions/{v}/pricing · POST versions/{v}/rates · POST versions/{v}/rates/clear
 *
 * Editing: every change goes through change()/commit() which records a diff command (undo/redo stack) and
 * schedules a debounced autosave (PUT with the draft `revision`; 409 = edited elsewhere → reload).
 */
(() => {
  const R = window.CommuneSpace;
  const { el, glyph, money } = R;
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const round3 = (v) => Math.round(v * 1000) / 1000;
  const clone = (o) => JSON.parse(JSON.stringify(o));
  const UNIT_LABEL = { day: 'per day', month: 'per month', hour: 'per hour' };
  const fmtDate = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  const SOURCE_LABEL = { seat: 'Seat override', zone: 'Zone rate', category: 'Base rate' };
  const isTyping = (e) => ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target?.tagName) || e.target?.isContentEditable;

  function pointInPoly(x, y, poly) {
    let inside = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const [xi, yi] = poly[i]; const [xj, yj] = poly[j];
      if (((yi > y) !== (yj > y)) && (x < ((xj - xi) * (y - yi)) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  }
  const zonePoly = (z) => (z.polygon && z.polygon.length >= 3 ? z.polygon : R.rectPoly(z));
  const polyArea = (p) => Math.abs(p.reduce((a, [x, y], i) => { const [x2, y2] = p[(i + 1) % p.length]; return a + x * y2 - x2 * y; }, 0)) / 2;
  const polyBounds = (p) => {
    const xs = p.map((q) => q[0]); const ys = p.map((q) => q[1]);
    return { x: Math.min(...xs), y: Math.min(...ys), w: Math.max(...xs) - Math.min(...xs), h: Math.max(...ys) - Math.min(...ys) };
  };

  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // ================================================================== Layout designer
    Alpine.data('layoutDesigner', () => ({
      cfg: {},
      doc: { zones: [], seats: [], placements: [] },
      draft: null,
      tool: 'select',
      sel: [],
      snap: true,
      gridPx: 16,
      scale: 1,
      tab: 'design',
      leftOpen: false,
      rightOpen: false,
      undoStack: [],
      redoStack: [],
      save: { state: 'saved', at: null, message: '' },
      pricing: null,
      priceForm: { unit: '', amount: '', gst_rate: 18, effective_from: '', note: '' },
      priceBusy: false,
      dlg: null,           // 'row' | 'grid' | 'cabin' | 'room' | 'renumber' | 'publish' | 'discard' | 'conflict'
      form: {},
      check: null,
      checkBusy: false,
      confirmWarnings: false,
      publishNotes: '',
      busy: false,
      toasts: [],
      drawing: null,       // polygon in progress: [[x,y],...]
      hover: null,
      facPick: '',

      // ------------------------------------------------------------ lifecycle
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.doc = this.cfg.doc;
        this.draft = this.cfg.draft;
        this.pricing = this.cfg.pricing;
        this.priceForm.gst_rate = this.cfg.gstDefault;
        this.priceForm.effective_from = this.cfg.today;
        this._seq = 1;
        this.els = {};
        if (Alpine.store('sidebar') && window.innerWidth < 1800) Alpine.store('sidebar').collapsed = true; // focus mode (not remembered)
        try { const p = JSON.parse(localStorage.getItem('commune.designer') || '{}'); if (p.snap !== undefined) this.snap = p.snap; if (p.grid) this.gridPx = p.grid; } catch { /* storage unavailable */ }
        this.$nextTick(() => {
          this.setupStage();
          this.render();
        });
        window.addEventListener('resize', () => this.fit());
        window.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('keyup', (e) => { if (e.code === 'Space' && this._spacePan) { this._spacePan = false; this.$refs.viewport?.classList.remove('is-hand'); } });
        window.addEventListener('beforeunload', (e) => { if (['dirty', 'saving', 'error'].includes(this.save.state)) { e.preventDefault(); e.returnValue = ''; } });
        this.$watch('snap', () => { this.persistPrefs(); this.render(); });
        this.$watch('gridPx', () => { this.persistPrefs(); this.render(); });
        this.$watch('sel', () => { this.syncPriceForm(); if (this.sel.length && window.innerWidth < 1280) this.rightOpen = true; });
        const qs = new URLSearchParams(window.location.search);
        if (qs.get('published')) this.toast(`Version ${qs.get('published')} is live. Visitors now see the new layout.`, 'success');
      },
      persistPrefs() { try { localStorage.setItem('commune.designer', JSON.stringify({ snap: this.snap, grid: this.gridPx })); } catch { /* ignore */ } },

      // ------------------------------------------------------------ getters
      get editable() { return !!this.draft; },
      get W() { return this.cfg.floor.width; },
      get H() { return this.cfg.floor.height; },
      get geo() { return { W: this.W, H: this.H }; },
      get versionId() { return this.draft?.id || this.cfg.published?.id || null; },
      get gx() { return (this.gridPx / this.W) * 100; },
      get gy() { return (this.gridPx / this.H) * 100; },
      get selSeats() { return this.sel.filter((r) => r.startsWith('s:')).map((r) => this.item(r)).filter(Boolean); },
      get selUnits() { return this.selSeats.filter((s) => s.parent_id === null); },
      get selZones() { return this.sel.filter((r) => r.startsWith('z:')).map((r) => this.item(r)).filter(Boolean); },
      get selFacs() { return this.sel.filter((r) => r.startsWith('f:')).map((r) => this.item(r)).filter(Boolean); },
      get one() { return this.sel.length === 1 ? this.item(this.sel[0]) : null; },
      get oneType() { return this.sel.length === 1 ? this.sel[0][0] : null; },
      get units() { return this.doc.seats.filter((s) => s.parent_id === null); },
      get dupes() {
        const n = {};
        for (const s of this.doc.seats) n[s.code] = (n[s.code] || 0) + 1;
        return new Set(Object.keys(n).filter((c) => n[c] > 1));
      },
      get stats() {
        const by = {};
        for (const u of this.units) {
          const c = this.catOfSeat(u);
          const k = c?.code || 'none';
          by[k] ||= { cat: c, units: 0, chairs: 0, blocked: 0 };
          by[k].units++;
          by[k].chairs += u.kind === 'seat' ? 1 : this.doc.seats.filter((x) => x.parent_id === u.id).length;
          if (u.status !== 'available') by[k].blocked++;
        }
        return Object.values(by);
      },
      get saveLabel() {
        return { saved: this.save.at ? `Saved ✓ ${this.save.at}` : 'Saved ✓', dirty: 'Unsaved changes…', saving: 'Saving…', error: 'Not saved — retrying', conflict: 'Conflict' }[this.save.state];
      },
      get undoLabel() { return this.undoStack.length ? `Undo ${this.undoStack[this.undoStack.length - 1].label} (Ctrl+Z)` : 'Nothing to undo'; },
      get redoLabel() { return this.redoStack.length ? `Redo ${this.redoStack[this.redoStack.length - 1].label} (Ctrl+Shift+Z)` : 'Nothing to redo'; },
      get facilitiesActive() { return this.cfg.facilities.filter((f) => f.active); },

      get selTitle() {
        if (!this.sel.length) return this.cfg.floor.name;
        if (this.sel.length === 1) { const it = this.one; return it?.code || it?.name || this.fac(it?.facility_id)?.name || ''; }
        const n = { s: 0, z: 0, f: 0 };
        for (const r of this.sel) n[r[0]]++;
        const parts = [];
        if (n.s) parts.push(`${n.s} seat${n.s === 1 ? '' : 's'}`);
        if (n.z) parts.push(`${n.z} zone${n.z === 1 ? '' : 's'}`);
        if (n.f) parts.push(`${n.f} facilit${n.f === 1 ? 'y' : 'ies'}`);
        return `${parts.join(' + ')} selected`;
      },
      num(v) { return Math.round(Number(v) * 100) / 100; },
      fmtDate,
      item(ref) {
        const [t, raw] = [ref[0], ref.slice(2)];
        const id = /^\d+$/.test(raw) ? Number(raw) : raw;
        const list = t === 's' ? this.doc.seats : t === 'z' ? this.doc.zones : this.doc.placements;
        return list.find((x) => x.id === id) || null;
      },
      ref(t, id) { return `${t}:${id}`; },
      isSel(t, id) { return this.sel.includes(this.ref(t, id)); },
      cat(id) { return this.cfg.categories.find((c) => c.id === id) || null; },
      catByCode(code) { return this.cfg.categories.find((c) => c.code === code) || null; },
      zone(id) { return this.doc.zones.find((z) => z.id === id) || null; },
      catOfSeat(s) { const p = s.parent_id !== null ? this.doc.seats.find((x) => x.id === s.parent_id) : s; return this.cat(this.zone(p?.zone_id)?.category_id); },
      fac(id) { return this.cfg.facilities.find((f) => f.id === id) || null; },
      childrenOf(id) { return this.doc.seats.filter((s) => s.parent_id === id); },
      tmp() { this._seq += 1; return `t${this._seq}`; },
      money,

      // ------------------------------------------------------------ stage, pan & zoom (Panzoom, noBind: we route pointers)
      setupStage() {
        const stage = this.$refs.stage;
        this.fit();
        if (window.Panzoom && stage) {
          this.pz = window.Panzoom(stage, { noBind: true, maxScale: 6, minScale: 0.4, step: 0.25, animate: true, duration: 180, canvas: true });
          stage.addEventListener('panzoomchange', () => { this.scale = this.pz.getScale(); });
        }
        const vp = this.$refs.viewport;
        // the viewport resizes when the sidebar collapses / panels open — keep the plan fitted
        if (window.ResizeObserver) new ResizeObserver(() => this.fit()).observe(vp);
        vp.addEventListener('wheel', (e) => {
          e.preventDefault();
          if (!this.pz) return;
          if (e.ctrlKey || e.metaKey) this.pz.zoomWithWheel(e, { animate: false });
          else { const s = this.pz.getScale(); this.pz.pan(-e.deltaX / s, -e.deltaY / s, { relative: true, animate: false }); }
        }, { passive: false });
        vp.addEventListener('dragover', (e) => { if (this.editable) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
        vp.addEventListener('drop', (e) => this.onDrop(e));
        const svg = this.$refs.svg;
        svg.addEventListener('pointerdown', (e) => this.onDown(e));
        svg.addEventListener('dblclick', (e) => this.onDbl(e));
        window.addEventListener('pointermove', (e) => this.onMove(e));
        window.addEventListener('pointerup', (e) => this.onUp(e));
      },
      fit() { R.fitStage(this.$refs.viewport, this.$refs.stage, this.W, this.H, { fill: true, pad: 28 }); },
      zoomIn() { this.pz?.zoomIn(); },
      zoomOut() { this.pz?.zoomOut(); },
      zoomReset() { this.pz?.reset(); },
      zoomTo(s) { this.pz?.zoom(s, { animate: true }); },
      /** Shift+2: zoom so the selection fills about half of the view, centred */
      zoomToSel() {
        const b = this.selBounds();
        const stage = this.$refs.stage; const vp = this.$refs.viewport;
        if (!b || !this.pz || !stage) return;
        const sw = stage.offsetWidth; const sh = stage.offsetHeight;
        const s = clamp(Math.min((vp.clientWidth * 0.55) / ((b.w / 100) * sw || 1), (vp.clientHeight * 0.55) / ((b.h / 100) * sh || 1)), 1, 5);
        // Panzoom: screen = O + s·(P − O + pan), O = stage centre (stage-local px) → pan that centres P in the viewport
        const P = { x: ((b.x + b.w / 2) / 100) * sw, y: ((b.y + b.h / 2) / 100) * sh };
        const V = { x: vp.clientWidth / 2 - stage.offsetLeft, y: vp.clientHeight / 2 - stage.offsetTop };
        this.pz.zoom(s, { animate: false });
        this.pz.pan((V.x - sw / 2) / s - P.x + sw / 2, (V.y - sh / 2) / s - P.y + sh / 2, { animate: true });
        this.render();
      },

      /** client px → percent of the image */
      toPct(e) {
        const svg = this.$refs.svg;
        const pt = svg.createSVGPoint();
        pt.x = e.clientX; pt.y = e.clientY;
        const p = pt.matrixTransform(svg.getScreenCTM().inverse());
        return { x: (p.x / this.W) * 100, y: (p.y / this.H) * 100 };
      },
      /** centre of the visible part of the plan, in percent */
      viewCentre() {
        const r = this.$refs.viewport.getBoundingClientRect();
        const p = this.toPct({ clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 });
        return { x: clamp(p.x, 5, 95), y: clamp(p.y, 5, 95) };
      },
      snapX(v) { return this.snap ? Math.round(v / this.gx) * this.gx : v; },
      snapY(v) { return this.snap ? Math.round(v / this.gy) * this.gy : v; },

      // ------------------------------------------------------------ rendering
      render() {
        const svg = this.$refs.svg;
        if (!svg) return;
        const geo = this.geo;
        svg.setAttribute('viewBox', `0 0 ${this.W} ${this.H}`);
        svg.innerHTML = '';
        this.els = {};
        const d = R.defs(svg);
        if (this.snap && this.editable) {
          const pat = el('pattern', { id: 'dz-grid', width: this.gridPx, height: this.gridPx, patternUnits: 'userSpaceOnUse' }, d);
          el('circle', { cx: 0.5, cy: 0.5, r: Math.max(0.8, 1.1 / this.scale), fill: 'rgba(15,23,42,.28)' }, pat);
          el('rect', { width: this.W, height: this.H, fill: 'url(#dz-grid)', class: 'dz-grid', 'pointer-events': 'none' }, svg);
        }
        const zoneLayer = el('g', { class: 'sx-zones' }, svg);
        const unitLayer = el('g', { class: 'sx-units' }, svg);
        const seatLayer = el('g', { class: 'sx-seats' }, svg);
        const facLayer = el('g', { class: 'sx-facs' }, svg);
        const labelLayer = el('g', { class: 'dz-labels' }, svg);
        this.overlay = el('g', { class: 'dz-overlay' }, svg);
        const dupes = this.dupes;

        for (const z of this.doc.zones) {
          const cat = this.cat(z.category_id);
          const poly = R.zonePolygon(zoneLayer, { ...z, colour: z.colour || cat?.colour }, geo, { 'data-ref': this.ref('z', z.id), class: `sx-zone dz-zone${this.isSel('z', z.id) ? ' is-selected' : ''}${cat ? '' : ' is-service'}` });
          this.els[this.ref('z', z.id)] = poly;
          // name chip in the zone's top-right corner (plan images usually print their own label top-left)
          const b = polyBounds(zonePoly(z));
          const label = b.w < 30 ? (cat ? cat.short : z.name) : `${z.name} · ${cat ? cat.short : 'not bookable'}`;
          const cw = label.length * 10.2 + 34;
          const chip = el('g', { class: `dz-zone-chip${this.isSel('z', z.id) ? ' is-selected' : ''}`, transform: `translate(${R.px(geo, b.x + b.w) - cw - 8},${R.py(geo, b.y) + 8})`, 'data-ref': this.ref('z', z.id) }, labelLayer);
          el('rect', { width: cw, height: 30, rx: 15 }, chip);
          el('circle', { cx: 15, cy: 15, r: 6, fill: z.colour || cat?.colour || '#94a3b8' }, chip);
          el('text', { x: 27, y: 20.5 }, chip).textContent = label;
        }
        for (const u of this.units) {
          const cat = this.catOfSeat(u);
          if (u.kind !== 'seat') {
            const g = R.unitBlock(unitLayer, { ...u, status: this.statusOf(u) }, geo, {
              line1: u.label || u.code,
              line2: `${cat?.short || 'No space type'} · ${u.capacity} seats`,
              hourly: !!cat?.hourly,
              attrs: { 'data-ref': this.ref('s', u.id) },
            });
            g.classList.toggle('is-selected', this.isSel('s', u.id));
            g.classList.toggle('is-dupe', dupes.has(u.code));
            this.els[this.ref('s', u.id)] = g;
            for (const c of this.childrenOf(u.id)) this.drawSeat(seatLayer, c, cat, dupes, true);
          } else {
            this.drawSeat(seatLayer, u, cat, dupes, false);
          }
        }
        for (const p of this.doc.placements) {
          if (p.x === null || p.y === null) continue;
          const f = this.fac(p.facility_id);
          if (!f) continue;
          const g = R.facility(facLayer, { ...f, x: p.x, y: p.y }, geo, { 'data-ref': this.ref('f', p.id) });
          g.classList.toggle('is-selected', this.isSel('f', p.id));
          g.classList.toggle('is-inactive', !f.active);
          this.els[this.ref('f', p.id)] = g;
        }
        this.drawOverlay();
      },
      drawSeat(layer, s, cat, dupes, child) {
        const g = R.chair(layer, s, this.geo, { child, attrs: { 'data-ref': this.ref('s', s.id) } });
        g.dataset.status = this.statusOf(s);
        g.style.setProperty('--seat-fill', cat?.colour || '#94a3b8');
        g.classList.toggle('is-selected', this.isSel('s', s.id));
        g.classList.toggle('is-dupe', dupes.has(s.code));
        g.classList.toggle('is-unzoned', !child && !cat);
        const glyphEl = g.querySelector('.glyph');
        if (glyphEl && s.status !== 'available') glyphEl.setAttribute('href', `#i-${s.status === 'maintenance' ? 'ban' : 'lock'}`);
        g.removeAttribute('role');
        g.removeAttribute('tabindex');
        el('title', {}, g).textContent = `${s.code}${cat ? ` · ${cat.short}` : ' · outside any zone'}${s.status !== 'available' ? ` · ${s.status}` : ''}`;
        this.els[this.ref('s', s.id)] = g;
      },
      statusOf(s) { return s.status === 'available' ? 'available' : 'blocked'; },

      /** selection box, zone vertex handles, rotate handle, polygon in progress */
      drawOverlay() {
        const o = this.overlay;
        if (!o) return;
        o.innerHTML = '';
        const geo = this.geo;
        const k = 1 / (this.scale || 1);
        if (this.editable && this.sel.length) {
          const b = this.selBounds();
          if (b) el('rect', { class: 'dz-selbox', x: R.px(geo, b.x) - 6 * k, y: R.py(geo, b.y) - 6 * k, width: R.px(geo, b.w) + 12 * k, height: R.py(geo, b.h) + 12 * k, rx: 6 * k, 'stroke-width': 1.5 * k }, o);
          const z = this.oneType === 'z' ? this.one : null;
          if (z) {
            const pts = zonePoly(z);
            pts.forEach(([x, y], i) => el('circle', { class: 'dz-handle', cx: R.px(geo, x), cy: R.py(geo, y), r: 7 * k, 'stroke-width': 2 * k, 'data-handle': `v${i}` }, o));
          }
          const s = this.oneType === 's' ? this.one : null;
          if (s) {
            const cx = R.px(geo, s.x + s.w / 2); const cy = R.py(geo, s.y + s.h / 2);
            const r = Math.max(R.px(geo, s.w), R.py(geo, s.h)) / 2 + 26 * k;
            const a = ((s.rotation || 0) - 90) * (Math.PI / 180);
            const hx = cx + r * Math.cos(a); const hy = cy + r * Math.sin(a);
            el('line', { class: 'dz-rotline', x1: cx, y1: cy, x2: hx, y2: hy, 'stroke-width': 1.5 * k }, o);
            el('circle', { class: 'dz-handle dz-rot', cx: hx, cy: hy, r: 7 * k, 'stroke-width': 2 * k, 'data-handle': 'rot' }, o);
          }
        }
        if (this.drawing?.length) {
          const pts = this.drawing.map(([x, y]) => `${R.px(geo, x)},${R.py(geo, y)}`).join(' ');
          el('polyline', { class: 'dz-draw', points: pts + (this.hover ? ` ${R.px(geo, this.hover.x)},${R.py(geo, this.hover.y)}` : ''), 'stroke-width': 2 * k }, o);
          this.drawing.forEach(([x, y], i) => el('circle', { class: `dz-handle${i === 0 ? ' dz-first' : ''}`, cx: R.px(geo, x), cy: R.py(geo, y), r: 6 * k, 'stroke-width': 2 * k }, o));
        }
        if (this._lasso) {
          const l = this._lasso;
          el('rect', { class: this.tool === 'rect' ? 'dz-draw-rect' : 'dz-lasso', x: R.px(geo, Math.min(l.x0, l.x1)), y: R.py(geo, Math.min(l.y0, l.y1)), width: R.px(geo, Math.abs(l.x1 - l.x0)), height: R.py(geo, Math.abs(l.y1 - l.y0)), 'stroke-width': 1.5 * k }, o);
        }
      },

      /** bounding box of the selection (percent) */
      selBounds() {
        const boxes = this.sel.map((r) => this.boxOf(r)).filter(Boolean);
        if (!boxes.length) return null;
        const x = Math.min(...boxes.map((b) => b.x)); const y = Math.min(...boxes.map((b) => b.y));
        return { x, y, w: Math.max(...boxes.map((b) => b.x + b.w)) - x, h: Math.max(...boxes.map((b) => b.y + b.h)) - y };
      },
      boxOf(ref) {
        const it = this.item(ref);
        if (!it) return null;
        if (ref[0] === 'z') return polyBounds(zonePoly(it));
        if (ref[0] === 'f') return { x: it.x - 1.2, y: it.y - 1.9, w: 2.4, h: 3.8 };
        return { x: it.x, y: it.y, w: it.w, h: it.h };
      },

      // ------------------------------------------------------------ pointer interaction
      refAt(target) {
        const g = target.closest?.('[data-ref]');
        return g ? g.dataset.ref : null;
      },
      onDown(e) {
        if (e.button === 1 || this.tool === 'hand' || this._spacePan) { this.startPan(e); return; }
        if (e.button !== 0) return;
        const p = this.toPct(e);
        if (!this.editable) { this.pick(this.refAt(e.target), e.shiftKey); return; }
        const handle = e.target.dataset?.handle;
        if (handle) { e.preventDefault(); this.startHandle(e, handle, p); return; }
        if (this.tool === 'poly') { this.polyClick(p); return; }
        if (this.tool === 'rect') { this._lasso = { x0: this.snapX(p.x), y0: this.snapY(p.y), x1: p.x, y1: p.y }; this._drag = { kind: 'rect' }; return; }
        let ref = this.refAt(e.target);
        // chairs of a cabin/room select their unit (double-click to pick the chair itself)
        if (ref && ref[0] === 's') { const s = this.item(ref); if (s?.parent_id !== null && !this.sel.includes(ref)) ref = this.ref('s', s.parent_id); }
        // zones are the background: dragging inside an unselected zone draws a lasso; a plain click selects the zone
        const zoneBody = ref && ref[0] === 'z' && e.target.tagName === 'polygon' && !this.sel.includes(ref);
        if (!ref || zoneBody) {
          if (!e.shiftKey) this.sel = [];
          this._lasso = { x0: p.x, y0: p.y, x1: p.x, y1: p.y, add: e.shiftKey, base: [...this.sel], zone: zoneBody ? ref : null, cx: e.clientX, cy: e.clientY };
          this._drag = { kind: 'lasso' };
          this.render();
          return;
        }
        if (e.shiftKey) {
          this.sel = this.sel.includes(ref) ? this.sel.filter((r) => r !== ref) : [...this.sel, ref];
          this.render();
          if (!this.sel.includes(ref)) return;
        } else if (!this.sel.includes(ref)) {
          this.sel = [ref];
          this.render();
        }
        this.startMove(e, p, ref);
      },
      pick(ref, add) {
        if (ref && ref[0] === 's') { const s = this.item(ref); if (s?.parent_id !== null) ref = this.ref('s', s.parent_id); }
        if (!ref) this.sel = [];
        else this.sel = add ? (this.sel.includes(ref) ? this.sel.filter((r) => r !== ref) : [...this.sel, ref]) : [ref];
        this.render();
      },
      onDbl(e) {
        if (this.tool === 'poly' && this.drawing?.length >= 3) { this.finishPoly(); return; }
        const ref = this.refAt(e.target);
        if (ref && ref[0] === 's') { this.sel = [ref]; this.render(); }
      },
      startPan(e) {
        e.preventDefault();
        this._drag = { kind: 'pan', x: e.clientX, y: e.clientY };
        this.$refs.viewport.classList.add('is-panning');
      },
      startMove(e, p, primaryRef) {
        // everything that moves: selected items + chairs of selected units + seats/placements inside selected zones
        const moving = new Set(this.sel);
        for (const z of this.selZones) {
          for (const s of this.doc.seats) if (s.zone_id === z.id && s.parent_id === null) moving.add(this.ref('s', s.id));
          for (const f of this.doc.placements) if (f.scope === 'zone' && f.scope_id === z.id) moving.add(this.ref('f', f.id));
        }
        for (const r of [...moving]) if (r[0] === 's') for (const c of this.childrenOf(this.item(r).id)) moving.add(this.ref('s', c.id));
        const start = {};
        for (const r of moving) {
          const it = this.item(r);
          start[r] = r[0] === 'z' ? { poly: it.polygon ? clone(it.polygon) : null, x: it.x, y: it.y } : { x: it.x, y: it.y };
        }
        this._drag = { kind: 'move', p0: p, start, primary: primaryRef, before: this.snapshot(), moved: false, x0: e.clientX, y0: e.clientY };
      },
      startHandle(e, handle, p) {
        const it = this.one;
        if (!it) return;
        this._drag = { kind: handle === 'rot' ? 'rotate' : 'vertex', index: handle === 'rot' ? -1 : Number(handle.slice(1)), ref: this.sel[0], before: this.snapshot(), p0: p, poly: clone(zonePoly(it)), wasRect: !it.polygon };
      },
      onMove(e) {
        const d = this._drag;
        if (this.tool === 'poly' && this.drawing?.length) { this.hover = this.toPct(e); this.drawOverlay(); }
        if (!d) return;
        if (d.kind === 'pan') {
          const s = this.pz?.getScale() || 1;
          this.pz?.pan((e.clientX - d.x) / s, (e.clientY - d.y) / s, { relative: true, animate: false });
          d.x = e.clientX; d.y = e.clientY;
          return;
        }
        const p = this.toPct(e);
        if (d.kind === 'lasso' || d.kind === 'rect') {
          this._lasso.x1 = d.kind === 'rect' ? this.snapX(p.x) : p.x;
          this._lasso.y1 = d.kind === 'rect' ? this.snapY(p.y) : p.y;
          if (d.kind === 'lasso') this.lassoSelect();
          this.drawOverlay();
          return;
        }
        if (d.kind === 'move') {
          if (!d.moved && Math.hypot(e.clientX - d.x0, e.clientY - d.y0) < 3) return;
          d.moved = true;
          let dx = p.x - d.p0.x; let dy = p.y - d.p0.y;
          // snap the primary item's top-left to the grid
          const ps = d.start[d.primary];
          if (ps && this.snap) {
            const b0 = d.primary[0] === 'z' ? polyBounds(ps.poly || R.rectPoly({ ...this.item(d.primary), x: ps.x, y: ps.y })) : ps;
            dx = this.snapX(b0.x + dx) - b0.x; dy = this.snapY(b0.y + dy) - b0.y;
          }
          [dx, dy] = this.clampDelta(d.start, dx, dy);
          this.applyDelta(d.start, dx, dy);
          this.render();
          return;
        }
        if (d.kind === 'rotate') {
          const s = this.item(d.ref);
          const cx = s.x + s.w / 2; const cy = s.y + s.h / 2;
          let a = (Math.atan2((p.y - cy) * this.H, (p.x - cx) * this.W) * 180) / Math.PI + 90;
          a = e.shiftKey ? Math.round(a) : Math.round(a / 15) * 15;
          s.rotation = ((a % 360) + 360) % 360;
          this.render();
          return;
        }
        if (d.kind === 'vertex') {
          const z = this.item(d.ref);
          const x = clamp(this.snapX(p.x), 0, 100); const y = clamp(this.snapY(p.y), 0, 100);
          if (d.wasRect) {
            // rectangles resize from the dragged corner (opposite corner fixed)
            const opp = d.poly[(d.index + 2) % 4];
            z.x = round3(Math.min(x, opp[0])); z.y = round3(Math.min(y, opp[1]));
            z.w = round3(Math.max(0.5, Math.abs(x - opp[0]))); z.h = round3(Math.max(0.5, Math.abs(y - opp[1])));
          } else {
            const poly = clone(d.poly);
            poly[d.index] = [round3(x), round3(y)];
            z.polygon = poly;
            Object.assign(z, this.roundRect(polyBounds(poly)));
          }
          this.render();
        }
      },
      onUp(e) {
        const d = this._drag;
        this._drag = null;
        if (!d) return;
        if (d.kind === 'pan') { this.$refs.viewport.classList.remove('is-panning'); return; }
        if (d.kind === 'lasso') {
          const l = this._lasso; this._lasso = null;
          if (l?.zone && Math.hypot(e.clientX - l.cx, e.clientY - l.cy) < 4) this.sel = l.add ? [...new Set([...l.base, l.zone])] : [l.zone];
          this.render();
          return;
        }
        if (d.kind === 'rect') {
          const l = this._lasso; this._lasso = null;
          const r = { x: Math.min(l.x0, l.x1), y: Math.min(l.y0, l.y1), w: Math.abs(l.x1 - l.x0), h: Math.abs(l.y1 - l.y0) };
          if (r.w < 1 || r.h < 1) { this.drawOverlay(); return; }
          this.addZone(r, null);
          this.tool = 'select';
          return;
        }
        if (d.kind === 'move') {
          if (!d.moved) {
            // plain click on an already-selected item without Shift: select only it
            if (!e.shiftKey && this.sel.length > 1 && this.sel.includes(d.primary)) { this.sel = [d.primary]; this.render(); }
            return;
          }
          this.rezone(Object.keys(d.start).filter((r) => r[0] === 's'));
          this.commit(d.before, `move ${this.describeSel()}`);
          return;
        }
        if (d.kind === 'rotate') { this.commit(d.before, 'rotate'); return; }
        if (d.kind === 'vertex') { this.rezoneAll(); this.commit(d.before, 'reshape zone'); }
      },
      clampDelta(start, dx, dy) {
        let minX = Infinity; let minY = Infinity; let maxX = -Infinity; let maxY = -Infinity;
        for (const [r, s] of Object.entries(start)) {
          const it = this.item(r);
          const b = r[0] === 'z' ? polyBounds(s.poly || R.rectPoly({ ...it, x: s.x, y: s.y })) : { x: s.x, y: s.y, w: it.w || 0, h: it.h || 0 };
          minX = Math.min(minX, b.x); minY = Math.min(minY, b.y); maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h);
        }
        return [clamp(dx, -minX, 100 - maxX), clamp(dy, -minY, 100 - maxY)];
      },
      applyDelta(start, dx, dy) {
        for (const [r, s] of Object.entries(start)) {
          const it = this.item(r);
          if (!it) continue;
          if (r[0] === 'z' && s.poly) { it.polygon = s.poly.map(([x, y]) => [round3(x + dx), round3(y + dy)]); Object.assign(it, this.roundRect(polyBounds(it.polygon))); } else { it.x = round3(s.x + dx); it.y = round3(s.y + dy); }
        }
      },
      roundRect(b) { return { x: round3(b.x), y: round3(b.y), w: round3(b.w), h: round3(b.h) }; },
      lassoSelect() {
        const l = this._lasso;
        const x0 = Math.min(l.x0, l.x1); const x1 = Math.max(l.x0, l.x1); const y0 = Math.min(l.y0, l.y1); const y1 = Math.max(l.y0, l.y1);
        const hit = [];
        for (const u of this.units) { const cx = u.x + u.w / 2; const cy = u.y + u.h / 2; if (cx >= x0 && cx <= x1 && cy >= y0 && cy <= y1) hit.push(this.ref('s', u.id)); }
        for (const f of this.doc.placements) if (f.x !== null && f.x >= x0 && f.x <= x1 && f.y >= y0 && f.y <= y1) hit.push(this.ref('f', f.id));
        const next = l.add ? [...new Set([...l.base, ...hit])] : hit;
        if (next.join() !== this.sel.join()) { this.sel = next; this.render(); }
      },

      // ------------------------------------------------------------ zones: drawing + membership
      polyClick(p) {
        const pt = [round3(clamp(this.snapX(p.x), 0, 100)), round3(clamp(this.snapY(p.y), 0, 100))];
        this.drawing ||= [];
        if (this.drawing.length >= 3) {
          const [fx, fy] = this.drawing[0];
          const screen = Math.hypot(((fx - pt[0]) / 100) * this.W, ((fy - pt[1]) / 100) * this.H) * (this.$refs.stage.offsetWidth / this.W) * (this.scale || 1);
          if (screen < 12) { this.finishPoly(); return; }
        }
        this.drawing.push(pt);
        this.drawOverlay();
      },
      finishPoly() {
        const pts = (this.drawing || []).filter((p, i, a) => i === 0 || Math.hypot(p[0] - a[i - 1][0], p[1] - a[i - 1][1]) > 0.05);
        this.drawing = null; this.hover = null;
        if (!pts || pts.length < 3) { this.drawOverlay(); return; }
        this.addZone(this.roundRect(polyBounds(pts)), pts);
        this.tool = 'select';
      },
      addZone(rect, polygon, { name = null, category = null } = {}) {
        const before = this.snapshot();
        const cat = category ? this.catByCode(category) : null;
        const z = {
          id: this.tmp(), key: null, code: this.nextZoneCode(), name: name || `Zone ${this.doc.zones.length + 1}`,
          category_id: cat?.id ?? null, colour: cat?.colour ?? '#64748b', polygon: polygon ? clone(polygon) : null,
          ...this.roundRect(rect), sort: this.doc.zones.length,
        };
        this.doc.zones.push(z);
        this.rezoneAll();
        this.sel = [this.ref('z', z.id)];
        this.tab = 'design';
        this.commit(before, 'add zone');
        return z;
      },
      nextZoneCode() {
        const used = new Set(this.doc.zones.map((z) => z.code));
        let i = this.doc.zones.length + 1;
        while (used.has(`Z${i}`)) i++;
        return `Z${i}`;
      },
      zoneAt(x, y) {
        let best = null; let bestA = Infinity;
        for (const z of this.doc.zones) {
          const poly = zonePoly(z);
          if (!pointInPoly(x, y, poly)) continue;
          const a = polyArea(poly) - (z.category_id ? 1e-6 : 0);
          if (a < bestA) { bestA = a; best = z; }
        }
        return best;
      },
      /** re-assign seats (units) to the zone under their centre; chairs follow their unit */
      rezone(refs) {
        for (const r of refs) {
          const s = this.item(r);
          if (!s || s.parent_id !== null) continue;
          const z = this.zoneAt(s.x + s.w / 2, s.y + s.h / 2);
          s.zone_id = z ? z.id : null;
          for (const c of this.childrenOf(s.id)) c.zone_id = s.zone_id;
        }
      },
      rezoneAll() { this.rezone(this.units.map((u) => this.ref('s', u.id))); },

      // ------------------------------------------------------------ commands (undo / redo) + autosave
      snapshot() {
        const m = new Map();
        for (const t of ['zones', 'seats', 'placements']) for (const it of this.doc[t]) m.set(`${t}|${it.id}`, JSON.stringify(it));
        return m;
      },
      /** Record the change since `before` as one undoable command. */
      commit(before, label) {
        const after = this.snapshot();
        const changes = [];
        for (const [k, v] of after) if (before.get(k) !== v) changes.push({ k, b: before.has(k) ? JSON.parse(before.get(k)) : null, a: JSON.parse(v) });
        for (const [k, v] of before) if (!after.has(k)) changes.push({ k, b: JSON.parse(v), a: null });
        if (!changes.length) { this.render(); return; }
        this.undoStack.push({ label, changes });
        if (this.undoStack.length > 200) this.undoStack.shift();
        this.redoStack = [];
        this.render();
        this.dirty();
      },
      /** mutate inside fn(); records the command */
      change(label, fn) {
        if (!this.editable) { this.toast('Start a draft to edit the layout.', 'info'); return; }
        const before = this.snapshot();
        fn();
        this.commit(before, label);
      },
      applyChanges(changes, dir) {
        for (const c of changes) {
          const [t, rawId] = c.k.split('|');
          const id = /^\d+$/.test(rawId) ? Number(rawId) : rawId;
          const target = dir === 'undo' ? c.b : c.a;
          const list = this.doc[t];
          const i = list.findIndex((x) => x.id === id);
          if (target === null) { if (i >= 0) list.splice(i, 1); } else if (i >= 0) list[i] = clone(target); else list.push(clone(target));
        }
        this.sel = this.sel.filter((r) => this.item(r));
        this.render();
        this.dirty();
      },
      undo() {
        const c = this.undoStack.pop();
        if (!c) return;
        this.applyChanges(c.changes, 'undo');
        this.redoStack.push(c);
        this.toast(`Undid ${c.label}`, 'info', null, 1600);
      },
      redo() {
        const c = this.redoStack.pop();
        if (!c) return;
        this.applyChanges(c.changes, 'redo');
        this.undoStack.push(c);
        this.toast(`Redid ${c.label}`, 'info', null, 1600);
      },
      dirty() {
        if (!this.editable) return;
        this._edits = (this._edits || 0) + 1;
        this.save.state = 'dirty';
        clearTimeout(this._saveT);
        this._saveT = setTimeout(() => this.flush(), 900);
      },
      /** Save now (one request at a time). Resolves true when the server has everything edited so far. */
      async flush() {
        clearTimeout(this._saveT);
        if (!this.editable) return true;
        while (this._saving) await this._saving;
        if (this.save.state === 'saved') return true;
        if (this.save.state === 'conflict') return false;
        const sent = this._edits;
        this.save.state = 'saving';
        this._saving = this.api('PUT', `/versions/${this.draft.id}`, { revision: this.draft.revision, doc: this.doc })
          .then((res) => {
            this.draft.revision = res.revision;
            this.remapIds(res.ids || {});
            if (Object.keys(res.ids || {}).length) this.refreshPricing();
            if (this._edits === sent) {
              this.save = { state: 'saved', at: new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }), message: '' };
              return true;
            }
            this.save.state = 'dirty'; // edited while saving: go again
            this._saveT = setTimeout(() => this.flush(), 300);
            return false;
          })
          .catch((e) => {
            if (e.status === 409) { this.save = { state: 'conflict', at: null, message: e.body?.message || 'This draft changed elsewhere.' }; this.dlg = 'conflict'; return false; }
            this.save = { state: 'error', at: null, message: e.body?.message || e.message };
            this.toast(e.body?.message || 'Could not save the draft.', e.status === 422 ? 'warning' : 'danger');
            if (e.status !== 422) this._saveT = setTimeout(() => this.flush(), 5000);
            return false;
          })
          .finally(() => { this._saving = null; });
        const ok = await this._saving;
        return ok || (this.save.state === 'dirty' ? this.flush() : false);
      },
      /** temp ids "t12" → row ids everywhere (doc, selection, undo/redo stacks) */
      remapIds(ids) {
        if (!Object.keys(ids).length) return;
        const m = (v) => (v !== null && v !== undefined && ids[String(v)] !== undefined ? ids[String(v)] : v);
        const fix = (it, t) => {
          if (!it) return;
          it.id = m(it.id);
          if (t === 'seats') { it.zone_id = m(it.zone_id); it.parent_id = m(it.parent_id); if (!it.key) it.key = it.id; }
          if (t === 'zones' && !it.key) it.key = it.id;
          if (t === 'placements') it.scope_id = m(it.scope_id);
        };
        for (const t of ['zones', 'seats', 'placements']) for (const it of this.doc[t]) fix(it, t);
        for (const stack of [this.undoStack, this.redoStack]) {
          for (const cmd of stack) {
            for (const c of cmd.changes) {
              const [t, rawId] = c.k.split('|');
              c.k = `${t}|${ids[rawId] !== undefined ? ids[rawId] : rawId}`;
              fix(c.b, t); fix(c.a, t);
            }
          }
        }
        const mref = (r) => { const raw = r.slice(2); return ids[raw] !== undefined ? `${r[0]}:${ids[raw]}` : r; };
        this.sel = this.sel.map(mref);
        // a drag in progress keeps working on the renamed items (and records its undo step under the new ids)
        const d = this._drag;
        if (d) {
          if (d.start) d.start = Object.fromEntries(Object.entries(d.start).map(([r, v]) => [mref(r), v]));
          if (d.primary) d.primary = mref(d.primary);
          if (d.ref) d.ref = mref(d.ref);
          if (d.before) {
            const next = new Map();
            for (const [k, v] of d.before) {
              const [t, rawId] = k.split('|');
              const it = JSON.parse(v);
              fix(it, t);
              next.set(`${t}|${ids[rawId] !== undefined ? ids[rawId] : rawId}`, JSON.stringify(it));
            }
            d.before = next;
          }
        }
        this.render();
      },

      // ------------------------------------------------------------ keyboard
      onKey(e) {
        if (isTyping(e) || this.dlg) { if (e.key === 'Escape' && this.dlg && this.dlg !== 'conflict') this.dlg = null; return; }
        const mod = e.ctrlKey || e.metaKey;
        if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) this.redo(); else this.undo(); return; }
        if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); this.redo(); return; }
        if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); this.duplicate(); return; }
        if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); this.sel = this.units.map((u) => this.ref('s', u.id)); this.render(); return; }
        if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); this.flush(); return; }
        if (e.code === 'Space') { if (!this._spacePan) { this._spacePan = true; this.$refs.viewport?.classList.add('is-hand'); } e.preventDefault(); return; }
        if (e.key === 'Escape') { if (this.drawing) { this.drawing = null; this.hover = null; this.drawOverlay(); } else { this.sel = []; this.render(); } this.tool = 'select'; return; }
        if (e.key === 'Enter' && this.drawing?.length >= 3) { this.finishPoly(); return; }
        if (mod) return;
        const tools = { v: 'select', h: 'hand', r: 'rect', p: 'poly' };
        if (tools[e.key.toLowerCase()] && !e.altKey) { this.setTool(tools[e.key.toLowerCase()]); return; }
        if (e.key === '+' || e.key === '=') { this.zoomIn(); return; }
        if (e.key === '-') { this.zoomOut(); return; }
        if (e.key === '0') { this.zoomReset(); return; }
        if (e.key === '@' || (e.shiftKey && e.code === 'Digit2')) { this.zoomToSel(); return; }
        if (!this.sel.length) return;
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.remove(); return; }
        if (e.key === ']' || e.key === '[') { this.rotateBy(e.key === ']' ? 15 : -15); return; }
        const dirs = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        if (dirs[e.key]) {
          e.preventDefault();
          const [ux, uy] = dirs[e.key];
          const step = e.shiftKey ? 10 : 1;
          this.nudge(ux * step * (this.snap ? this.gx : 0.1), uy * step * (this.snap ? this.gy : 0.1));
        }
      },
      setTool(t) {
        if (!this.editable && ['rect', 'poly'].includes(t)) { this.toast('Start a draft to draw zones.', 'info'); return; }
        this.tool = t;
        if (t !== 'poly') { this.drawing = null; this.hover = null; this.drawOverlay(); }
      },
      nudge(dx, dy) {
        this.change('nudge', () => {
          const start = {};
          const refs = new Set(this.sel);
          for (const z of this.selZones) for (const s of this.doc.seats) if (s.zone_id === z.id && s.parent_id === null) refs.add(this.ref('s', s.id));
          for (const r of [...refs]) if (r[0] === 's') for (const c of this.childrenOf(this.item(r).id)) refs.add(this.ref('s', c.id));
          for (const r of refs) { const it = this.item(r); start[r] = r[0] === 'z' ? { poly: it.polygon ? clone(it.polygon) : null, x: it.x, y: it.y } : { x: it.x, y: it.y }; }
          const [cx, cy] = this.clampDelta(start, dx, dy);
          this.applyDelta(start, cx, cy);
          this.rezone([...refs].filter((r) => r[0] === 's'));
        });
      },

      // ------------------------------------------------------------ edit actions
      describeSel() {
        const n = this.sel.length;
        if (n === 1) { const it = this.one; return it?.code || it?.name || this.fac(it?.facility_id)?.name || 'item'; }
        return `${n} items`;
      },
      remove() {
        if (!this.sel.length) return;
        const zones = this.selZones;
        const inZones = zones.length ? this.doc.seats.filter((s) => zones.some((z) => z.id === s.zone_id)).length : 0;
        if (inZones && !window.confirm(`Delete ${zones.length === 1 ? `zone “${zones[0].name}”` : `${zones.length} zones`} and the ${inZones} seats inside?`)) return;
        this.change(`delete ${this.describeSel()}`, () => {
          const kill = new Set();
          for (const s of this.selSeats) { kill.add(s.id); for (const c of this.childrenOf(s.id)) kill.add(c.id); }
          const zoneIds = new Set(zones.map((z) => z.id));
          for (const s of this.doc.seats) if (zoneIds.has(s.zone_id)) kill.add(s.id);
          const facIds = new Set(this.selFacs.map((f) => f.id));
          this.doc.placements = this.doc.placements.filter((p) => !facIds.has(p.id) && !(p.scope === 'seat' && kill.has(p.scope_id)) && !(p.scope === 'zone' && zoneIds.has(p.scope_id)));
          this.doc.seats = this.doc.seats.filter((s) => !kill.has(s.id));
          this.doc.zones = this.doc.zones.filter((z) => !zoneIds.has(z.id));
          this.sel = [];
        });
      },
      duplicate() {
        if (!this.sel.length) return;
        this.change(`duplicate ${this.describeSel()}`, () => {
          const off = { x: this.snap ? this.gx * 2 : 1.5, y: this.snap ? this.gy * 2 : 1.5 };
          const next = [];
          for (const z of this.selZones) {
            const nz = { ...clone(z), id: this.tmp(), key: null, code: this.nextZoneCode(), name: `${z.name} copy`, polygon: z.polygon ? z.polygon.map(([x, y]) => [round3(clamp(x + off.x, 0, 100)), round3(clamp(y + off.y, 0, 100))]) : null };
            nz.x = round3(clamp(z.x + off.x, 0, 100 - z.w)); nz.y = round3(clamp(z.y + off.y, 0, 100 - z.h));
            this.doc.zones.push(nz);
            next.push(this.ref('z', nz.id));
          }
          for (const u of this.selUnits) {
            const nu = this.copySeat(u, off, null);
            next.push(this.ref('s', nu.id));
            for (const c of this.childrenOf(u.id)) this.copySeat(c, off, nu);
          }
          for (const f of this.selFacs) {
            const nf = { ...clone(f), id: this.tmp(), x: round3(clamp(f.x + off.x, 0, 100)), y: round3(clamp(f.y + off.y, 0, 100)) };
            this.doc.placements.push(nf);
            next.push(this.ref('f', nf.id));
          }
          this.rezoneAll();
          this.sel = next;
        });
      },
      copySeat(s, off, parent) {
        const n = { ...clone(s), id: this.tmp(), key: null, x: round3(clamp(s.x + off.x, 0, 100 - s.w)), y: round3(clamp(s.y + off.y, 0, 100 - s.h)), parent_id: parent ? parent.id : null };
        if (parent) {
          n.zone_id = parent.zone_id;
          const letter = parent.code.split('-').pop();
          const i = this.childrenOf(parent.id).length + 1;
          n.code = `${parent.code}${i}`; n.label = `${letter}${i}`;
        } else if (s.kind === 'seat') {
          const cat = this.catOfSeat(s);
          n.code = this.nextSeatCode(cat); n.label = n.code.split('-').pop();
        } else {
          const letter = this.nextLetter(s.kind === 'room' ? 'CF' : 'CB');
          n.code = `${this.cfg.floor.code}-${s.kind === 'room' ? 'CF' : 'CB'}-${letter}`;
          n.label = `${s.kind === 'room' ? 'Conference' : 'Cabin'} ${letter}`;
        }
        this.doc.seats.push(n);
        return n;
      },
      rotateBy(deg) {
        const seats = this.selUnits;
        if (!seats.length) return;
        this.change('rotate', () => { for (const s of seats) { s.rotation = (((s.rotation || 0) + deg) % 360 + 360) % 360; for (const c of this.childrenOf(s.id)) c.rotation = s.rotation; } });
      },

      // ------------------------------------------------------------ auto-numbering
      segment(cat) { return cat?.segment || 'ST'; },
      nextSeatCode(cat, taken = null) {
        const prefix = `${this.cfg.floor.code}-${this.segment(cat)}-`;
        let max = 0;
        for (const s of this.doc.seats) if (s.code.startsWith(prefix)) { const n = parseInt(s.code.slice(prefix.length), 10); if (!Number.isNaN(n)) max = Math.max(max, n); }
        if (taken) for (const c of taken) if (c.startsWith(prefix)) max = Math.max(max, parseInt(c.slice(prefix.length), 10) || 0);
        return `${prefix}${String(max + 1).padStart(2, '0')}`;
      },
      nextLetter(seg) {
        const used = new Set(this.doc.seats.filter((s) => s.code.startsWith(`${this.cfg.floor.code}-${seg}-`)).map((s) => s.code.split('-').pop().replace(/\d+$/, '')));
        for (const l of 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') if (!used.has(l)) return l;
        return 'X';
      },
      seatSize(cat) {
        const same = this.units.filter((u) => u.kind === 'seat' && this.catOfSeat(u)?.id === cat?.id);
        if (same.length) return { w: same[0].w, h: same[0].h };
        const w = 3.4; return { w, h: round3((w * this.W) / this.H) };
      },
      openRenumber() {
        const seats = this.selUnits.filter((s) => s.kind === 'seat');
        if (!seats.length) { this.toast('Select seats to renumber.', 'info'); return; }
        const cat = this.catOfSeat(seats[0]);
        const nums = seats.map((s) => parseInt(s.code.split('-').pop(), 10)).filter((n) => !Number.isNaN(n));
        this.form = { prefix: `${this.cfg.floor.code}-${this.segment(cat)}-`, start: nums.length ? Math.min(...nums) : 1, digits: 2 };
        this.dlg = 'renumber';
      },
      /** reading order: rows (by centre y, tolerance half a seat) then left → right */
      readingOrder(seats) {
        const sorted = [...seats].sort((a, b) => (a.y + a.h / 2) - (b.y + b.h / 2));
        const rows = [];
        for (const s of sorted) {
          const cy = s.y + s.h / 2;
          const row = rows.find((r) => Math.abs(r.cy - cy) < s.h / 2);
          if (row) row.items.push(s); else rows.push({ cy, items: [s] });
        }
        return rows.flatMap((r) => r.items.sort((a, b) => a.x - b.x));
      },
      applyRenumber() {
        const f = this.form;
        const seats = this.readingOrder(this.selUnits.filter((s) => s.kind === 'seat'));
        const prefix = String(f.prefix || '').toUpperCase().trim();
        if (!/^[A-Z0-9][A-Z0-9-]*$/.test(prefix)) { this.toast('Use letters, digits and dashes in the prefix.', 'warning'); return; }
        this.change(`renumber ${seats.length} seats`, () => {
          seats.forEach((s, i) => { const n = String(Number(f.start) + i).padStart(Number(f.digits) || 2, '0'); s.code = `${prefix}${n}`.slice(0, 20); s.label = n; });
        });
        this.dlg = null;
        const clash = seats.filter((s) => this.dupes.has(s.code)).length;
        this.toast(clash ? `${seats.length} seats renumbered — ${clash} codes clash with other seats (shown in red).` : `${seats.length} seats renumbered in reading order.`, clash ? 'warning' : 'success');
      },

      // ------------------------------------------------------------ palette: seats, rows, grids, cabins, rooms, facilities
      paletteDrag(e, payload) {
        e.dataTransfer.setData('text/x-commune', JSON.stringify(payload));
        e.dataTransfer.effectAllowed = 'copy';
      },
      onDrop(e) {
        const raw = e.dataTransfer.getData('text/x-commune');
        if (!raw || !this.editable) return;
        e.preventDefault();
        const payload = JSON.parse(raw);
        const p = this.toPct(e);
        if (p.x < 0 || p.x > 100 || p.y < 0 || p.y > 100) { this.toast('Drop onto the floor plan.', 'info'); return; }
        if (payload.type === 'seat') this.addSeats([{ x: p.x, y: p.y }], { centred: true });
        else if (payload.type === 'facility') this.placeFacility(payload.id, p, this.refAt(document.elementFromPoint(e.clientX, e.clientY)));
      },
      addSeatAtCentre() { this.addSeats([this.viewCentre()], { centred: true }); },
      /** points = seat centres (centred) or top-lefts */
      addSeats(points, { centred = false, cat = undefined } = {}) {
        if (!this.editable) { this.toast('Start a draft to edit the layout.', 'info'); return []; }
        const before = this.snapshot();
        const made = [];
        for (const pt of points) {
          const z = this.zoneAt(pt.x, pt.y);
          const c = cat !== undefined ? cat : this.cat(z?.category_id);
          const size = this.seatSize(c);
          let x = centred ? pt.x - size.w / 2 : pt.x; let y = centred ? pt.y - size.h / 2 : pt.y;
          x = round3(clamp(this.snapX(x), 0, 100 - size.w)); y = round3(clamp(this.snapY(y), 0, 100 - size.h));
          const code = this.nextSeatCode(c);
          const s = { id: this.tmp(), key: null, zone_id: null, parent_id: null, code, label: code.split('-').pop(), kind: 'seat', capacity: 1, x, y, w: size.w, h: size.h, rotation: 0, status: 'available', status_from: null, status_to: null, notes: '', tags: '' };
          this.doc.seats.push(s);
          this.rezone([this.ref('s', s.id)]);
          made.push(s);
        }
        this.sel = made.map((s) => this.ref('s', s.id));
        this.tab = 'design';
        this.commit(before, made.length === 1 ? `add seat ${made[0].code}` : `add ${made.length} seats`);
        return made;
      },
      openDialog(kind) {
        if (!this.editable) { this.toast('Start a draft to edit the layout.', 'info'); return; }
        const cats = this.cfg.categories;
        const firstZone = (code) => this.doc.zones.find((z) => this.cat(z.category_id)?.code === code);
        this.form = {
          row: { count: 4, gap: 1.2, dir: 'h', category: (this.selZones[0] && this.cat(this.selZones[0].category_id)?.code) || 'FLEXI' },
          grid: { rows: 2, cols: 4, gapX: 1.2, gapY: 2.5, category: 'DEDICATED' },
          cabin: { letter: this.nextLetter('CB'), chairs: 3, w: 12.5, h: 39 },
          room: { letter: this.nextLetter('CF'), chairs: 9, w: 28, h: 34 },
        }[kind];
        this.form.kind = kind;
        this.form.hasZone = !!firstZone(this.form.category);
        this.form.cats = cats;
        this.dlg = kind;
      },
      /** "add row of N" / "add grid R×C": placed at the view centre (inside a zone of the category when possible) */
      applyRowGrid() {
        const f = this.form;
        const cat = this.catByCode(f.category);
        const size = this.seatSize(cat);
        const rows = f.kind === 'grid' ? clamp(Number(f.rows) || 1, 1, 30) : (f.dir === 'v' ? clamp(Number(f.count) || 1, 1, 60) : 1);
        const cols = f.kind === 'grid' ? clamp(Number(f.cols) || 1, 1, 30) : (f.dir === 'v' ? 1 : clamp(Number(f.count) || 1, 1, 60));
        const gx = Number(f.kind === 'grid' ? f.gapX : f.gap) || 0; const gy = Number(f.kind === 'grid' ? f.gapY : f.gap) * (this.W / this.H) || 0;
        const totalW = cols * size.w + (cols - 1) * gx; const totalH = rows * size.h + (rows - 1) * gy;
        if (totalW > 100 || totalH > 100) { this.toast('That does not fit on the plan — use fewer seats or a smaller gap.', 'warning'); return; }
        const sel = this.selZones[0];
        const zc = sel && this.cat(sel.category_id)?.code === f.category ? sel : null;
        const c = zc ? polyBounds(zonePoly(zc)) : null;
        const centre = c ? { x: c.x + c.w / 2, y: c.y + c.h / 2 } : this.viewCentre();
        const x0 = clamp(centre.x - totalW / 2, 0, 100 - totalW); const y0 = clamp(centre.y - totalH / 2, 0, 100 - totalH);
        const pts = [];
        for (let r = 0; r < rows; r++) for (let k = 0; k < cols; k++) pts.push({ x: x0 + k * (size.w + gx), y: y0 + r * (size.h + gy) });
        this.dlg = null;
        const snapWas = this.snap; this.snap = false;
        const made = this.addSeats(pts, { cat });
        this.snap = snapWas;
        const outside = made.filter((s) => !s.zone_id).length;
        this.toast(`${made.length} seats added${outside ? ` — ${outside} are outside a ${cat.short} zone; drag them into one` : ''}. Drag to position, then renumber if needed.`, outside ? 'warning' : 'success');
      },
      /** cabin (parent + N chairs in a row) or conference room (table with chairs around), each in its own zone */
      applyUnit() {
        const f = this.form;
        const isRoom = f.kind === 'room';
        const cat = this.catByCode(isRoom ? 'CONF' : 'CABIN');
        const chairs = clamp(Number(f.chairs) || 1, 1, 40);
        const letter = String(f.letter || 'X').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3) || 'X';
        const w = clamp(Number(f.w) || 12, 4, 60); const h = clamp(Number(f.h) || 20, 4, 80);
        const c = this.viewCentre();
        const rect = { x: round3(clamp(c.x - w / 2, 0, 100 - w)), y: round3(clamp(c.y - h / 2, 0, 100 - h)), w, h };
        const seg = isRoom ? 'CF' : 'CB';
        const code = `${this.cfg.floor.code}-${seg}-${letter}`;
        if (this.doc.seats.some((s) => s.code === code)) { this.toast(`${code} already exists — pick another letter.`, 'warning'); return; }
        const before = this.snapshot();
        const zone = { id: this.tmp(), key: null, code: `${seg}-${letter}`, name: `${isRoom ? 'Conference Room' : 'Cabin'} ${letter}`, category_id: cat?.id ?? null, colour: cat?.colour ?? '#64748b', polygon: null, ...rect, sort: this.doc.zones.length };
        this.doc.zones.push(zone);
        const unit = { id: this.tmp(), key: null, zone_id: zone.id, parent_id: null, code, label: `${isRoom ? 'Conference' : 'Cabin'} ${letter}`, kind: isRoom ? 'room' : 'cabin', capacity: chairs, x: round3(rect.x + 0.8), y: round3(rect.y + 1.4), w: round3(w - 1.6), h: round3(h - 2.8), rotation: 0, status: 'available', status_from: null, status_to: null, notes: '', tags: '' };
        this.doc.seats.push(unit);
        const cw = 2.6; const ch = round3((cw * this.W) / this.H); const gap = 0.8;
        let pos = [];
        if (isRoom) {
          const t = { x: rect.x + w * 0.24, y: rect.y + h * 0.36, w: w * 0.6, h: h * 0.3 };
          const per = Math.floor((chairs - 1) / 2) || 1; const cell = t.w / per;
          for (let i = 0; i < per && pos.length < chairs; i++) pos.push([t.x + i * cell + (cell - cw) / 2, t.y - ch - 0.8]);
          for (let i = 0; i < per && pos.length < chairs; i++) pos.push([t.x + i * cell + (cell - cw) / 2, t.y + t.h + 0.8]);
          while (pos.length < chairs) pos.push([t.x - cw - 0.8, t.y + (t.h - ch) / 2]);
        } else {
          const rowW = chairs * cw + (chairs - 1) * gap;
          const sx = rect.x + (w - rowW) / 2; const y = rect.y + h * 0.52;
          pos = Array.from({ length: chairs }, (_, i) => [sx + i * (cw + gap), y]);
        }
        pos.forEach(([x, y], i) => this.doc.seats.push({ id: this.tmp(), key: null, zone_id: zone.id, parent_id: unit.id, code: `${code}${i + 1}`, label: `${letter}${i + 1}`, kind: 'seat', capacity: 1, x: round3(clamp(x, 0, 100 - cw)), y: round3(clamp(y, 0, 100 - ch)), w: cw, h: ch, rotation: 0, status: 'available', status_from: null, status_to: null, notes: '', tags: '' }));
        this.sel = [this.ref('s', unit.id)];
        this.dlg = null;
        this.commit(before, `add ${unit.label}`);
        this.toast(`${unit.label} added with ${chairs} chairs — drag it into place.`, 'success');
      },
      placeFacility(facilityId, p, targetRef) {
        const f = this.fac(Number(facilityId));
        if (!f) return;
        let scope = 'floor'; let scopeId = null;
        if (targetRef?.[0] === 's') { const s = this.item(targetRef); const u = s?.parent_id !== null ? this.item(this.ref('s', s.parent_id)) : s; if (u) { scope = 'seat'; scopeId = u.id; } } else {
          const z = this.zoneAt(p.x, p.y);
          if (z && f.kind !== 'landmark') { scope = 'zone'; scopeId = z.id; }
        }
        const pl = { id: this.tmp(), facility_id: f.id, scope, scope_id: scopeId, x: round3(clamp(this.snapX(p.x), 0.5, 99.5)), y: round3(clamp(this.snapY(p.y), 0.5, 99.5)), note: '' };
        this.change(`place ${f.name}`, () => { this.doc.placements.push(pl); this.sel = [this.ref('f', pl.id)]; });
        this.toast(`${f.emoji || ''} ${f.name} placed on ${scope === 'floor' ? 'the floor' : scope === 'zone' ? `zone ${this.zone(scopeId)?.name}` : `seat ${this.item(this.ref('s', scopeId))?.code}`}.`, 'success', null, 2600);
      },
      /** inspector: attach a facility to the selected units / zone without drawing it */
      assignFacility() {
        const fid = Number(this.facPick);
        if (!fid) return;
        const targets = this.selZones.length ? this.selZones.map((z) => ['zone', z.id]) : this.selUnits.map((s) => ['seat', s.id]);
        if (!targets.length) return;
        this.change('attach facility', () => {
          for (const [scope, id] of targets) {
            if (this.doc.placements.some((p) => p.facility_id === fid && p.scope === scope && p.scope_id === id)) continue;
            this.doc.placements.push({ id: this.tmp(), facility_id: fid, scope, scope_id: id, x: null, y: null, note: '' });
          }
        });
        this.facPick = '';
      },
      attached(scope, id) { return this.doc.placements.filter((p) => p.scope === scope && p.scope_id === id); },
      detach(p) { this.change('remove facility', () => { this.doc.placements = this.doc.placements.filter((x) => x.id !== p.id); }); },

      // ------------------------------------------------------------ align / distribute
      align(how) {
        const refs = this.sel.filter((r) => r[0] !== 'z');
        if (refs.length < 2) return;
        const boxes = refs.map((r) => ({ r, it: this.item(r), b: this.boxOf(r) }));
        const L = Math.min(...boxes.map((x) => x.b.x)); const Rr = Math.max(...boxes.map((x) => x.b.x + x.b.w));
        const T = Math.min(...boxes.map((x) => x.b.y)); const B = Math.max(...boxes.map((x) => x.b.y + x.b.h));
        this.change(`align ${how}`, () => {
          for (const { r, it, b } of boxes) {
            let dx = 0; let dy = 0;
            if (how === 'left') dx = L - b.x; if (how === 'right') dx = Rr - (b.x + b.w); if (how === 'hcenter') dx = (L + Rr) / 2 - (b.x + b.w / 2);
            if (how === 'top') dy = T - b.y; if (how === 'bottom') dy = B - (b.y + b.h); if (how === 'vcenter') dy = (T + B) / 2 - (b.y + b.h / 2);
            it.x = round3(it.x + dx); it.y = round3(it.y + dy);
            if (r[0] === 's') for (const c of this.childrenOf(it.id)) { c.x = round3(c.x + dx); c.y = round3(c.y + dy); }
          }
          this.rezone(refs.filter((r) => r[0] === 's'));
        });
      },
      distribute(axis) {
        const refs = this.sel.filter((r) => r[0] !== 'z');
        if (refs.length < 3) { this.toast('Select at least 3 items to distribute.', 'info'); return; }
        const items = refs.map((r) => ({ r, it: this.item(r), b: this.boxOf(r) })).sort((a, b) => (axis === 'h' ? a.b.x - b.b.x : a.b.y - b.b.y));
        const k = axis === 'h' ? 'x' : 'y'; const s = axis === 'h' ? 'w' : 'h';
        const first = items[0].b[k]; const last = items[items.length - 1].b[k] + items[items.length - 1].b[s];
        const total = items.reduce((a, x) => a + x.b[s], 0);
        const gap = (last - first - total) / (items.length - 1);
        this.change(`distribute ${axis === 'h' ? 'horizontally' : 'vertically'}`, () => {
          let pos = first;
          for (const { r, it, b } of items) {
            const d = pos - b[k];
            it[k] = round3(it[k] + d);
            if (r[0] === 's') for (const c of this.childrenOf(it.id)) c[k] = round3(c[k] + d);
            pos += b[s] + gap;
          }
          this.rezone(refs.filter((r) => r[0] === 's'));
        });
      },

      // ------------------------------------------------------------ inspector edits
      /** set a field on every selected item of a type, as one command */
      setField(type, field, value, label = null) {
        const items = type === 's' ? this.selSeats : type === 'z' ? this.selZones : this.selFacs;
        if (!items.length) return;
        this.change(label || `edit ${field}`, () => {
          for (const it of items) {
            if (['x', 'y', 'w', 'h'].includes(field)) {
              const v = round3(Number(value));
              if (Number.isNaN(v)) continue;
              if (field === 'x' || field === 'y') {
                const d = clamp(v, 0, 100 - (it[field === 'x' ? 'w' : 'h'] || 0)) - it[field];
                it[field] = round3(it[field] + d);
                if (type === 's') for (const c of this.childrenOf(it.id)) c[field] = round3(c[field] + d);
              } else it[field] = clamp(v, 0.3, 100 - it[field === 'w' ? 'x' : 'y']);
              if (type === 'z' && it.polygon) it.polygon = null;
            } else if (field === 'rotation') {
              it.rotation = ((Math.round(Number(value) || 0) % 360) + 360) % 360;
              for (const c of this.childrenOf(it.id)) c.rotation = it.rotation;
            } else if (field === 'code') {
              it.code = String(value).toUpperCase().replace(/[^A-Z0-9-]/g, '').slice(0, 20);
            } else if (field === 'category_id') {
              it.category_id = value === '' || value === null ? null : Number(value);
              const cat = this.cat(it.category_id);
              if (cat) it.colour = cat.colour;
            } else if (field === 'status') {
              it.status = value;
              if (value === 'available') { it.status_from = null; it.status_to = null; }
            } else if (field === 'capacity') {
              it.capacity = clamp(Math.round(Number(value) || 1), 1, 99);
            } else if (field === 'facility_id') {
              it.facility_id = Number(value);
            } else {
              it[field] = value;
            }
          }
          if (['x', 'y', 'w', 'h'].includes(field)) type === 'z' ? this.rezoneAll() : this.rezone(this.sel.filter((r) => r[0] === 's'));
        });
      },
      moveToZone(zoneId) {
        const z = this.zone(zoneId === '' ? null : (/^\d+$/.test(zoneId) ? Number(zoneId) : zoneId));
        if (!z) return;
        this.change('move to zone', () => {
          for (const s of this.selUnits) {
            const b = polyBounds(zonePoly(z));
            s.x = round3(clamp(b.x + b.w / 2 - s.w / 2, 0, 100 - s.w)); s.y = round3(clamp(b.y + b.h / 2 - s.h / 2, 0, 100 - s.h));
            s.zone_id = z.id;
          }
        });
      },
      seatZoneName(s) { const z = this.zone(s.zone_id); return z ? z.name : 'Outside any zone'; },

      // ------------------------------------------------------------ pricing
      async refreshPricing() {
        if (!this.versionId) return;
        try { this.pricing = await this.api('GET', `/versions/${this.versionId}/pricing`); } catch { /* keep old */ }
      },
      priceTarget() {
        if (this.selZones.length === 1 && !this.selSeats.length) return { target: 'zone', items: this.selZones };
        const units = this.selUnits;
        return units.length ? { target: 'seat', items: units } : null;
      },
      /** the space type(s) of the price target → which units are allowed */
      get priceUnits() {
        const t = this.priceTarget();
        if (!t) return [];
        const cats = new Set(t.items.map((i) => (t.target === 'zone' ? this.cat(i.category_id) : this.catOfSeat(i))?.code));
        if (cats.size !== 1 || cats.has(undefined)) return [];
        return this.catByCode([...cats][0]).units;
      },
      syncPriceForm() {
        const u = this.priceUnits;
        if (!u.includes(this.priceForm.unit)) this.priceForm.unit = u.includes('month') ? 'month' : (u[0] || '');
        const one = this.one;
        const cur = one && this.oneType === 's' ? this.resolved(one)?.[this.priceForm.unit] : null;
        this.priceForm.amount = cur ? cur.amount : '';
        this.priceForm.gst_rate = cur ? cur.gst_rate : this.cfg.gstDefault;
      },
      resolved(s) { return this.pricing?.seats?.[s.id]?.resolved || null; },
      overrides(s) { return this.pricing?.seats?.[s.id]?.override || {}; },
      zoneRates(z) { return this.pricing?.zones?.[z.id] || {}; },
      catRates(catId) { return this.pricing?.categories?.[catId] || {}; },
      rateSource(src, s) {
        if (src === 'category') return `${SOURCE_LABEL.category} · ${this.catOfSeat(s)?.short || ''}`;
        if (src === 'zone') return `${SOURCE_LABEL.zone} · ${this.zone(s.zone_id)?.name || ''}`;
        return SOURCE_LABEL[src] || src;
      },
      unitLabel(u) { return UNIT_LABEL[u] || u; },
      /** price summary for a multi-selection: distinct resolved amounts per unit */
      bulkPrices() {
        const out = {};
        for (const s of this.selUnits) for (const [u, r] of Object.entries(this.resolved(s) || {})) { out[u] ||= new Set(); out[u].add(r.amount); }
        return Object.entries(out).map(([u, set]) => ({ unit: u, values: [...set].sort((a, b) => a - b) }));
      },
      async submitPrice() {
        const t = this.priceTarget();
        if (!t || !this.priceForm.unit) return;
        this.priceBusy = true;
        try {
          if (!(await this.flush())) return;
          const ids = t.items.map((i) => i.id).filter((id) => typeof id === 'number');
          const res = await this.api('POST', `/versions/${this.versionId}/rates`, { target: t.target, ids, ...this.priceForm });
          this.pricing = res.pricing;
          this.toast(`${money(this.priceForm.amount)} ${this.unitLabel(this.priceForm.unit)} set on ${t.target === 'zone' ? `zone ${t.items[0].name}` : (ids.length === 1 ? t.items[0].code : `${ids.length} seats`)} from ${fmtDate(this.priceForm.effective_from)}.`, 'success');
        } catch (e) { this.handleError(e); } finally { this.priceBusy = false; }
      },
      async clearPrice(unit = null) {
        const t = this.priceTarget();
        if (!t) return;
        this.priceBusy = true;
        try {
          if (!(await this.flush())) return;
          const res = await this.api('POST', `/versions/${this.versionId}/rates/clear`, { target: t.target, ids: t.items.map((i) => i.id).filter((id) => typeof id === 'number'), unit, effective_from: this.priceForm.effective_from });
          this.pricing = res.pricing;
          this.toast(res.cleared ? 'Override removed — the zone / base rate applies again.' : 'There was no override to clear.', res.cleared ? 'success' : 'info');
        } catch (e) { this.handleError(e); } finally { this.priceBusy = false; }
      },

      // ------------------------------------------------------------ draft lifecycle
      async startDraft() {
        this.busy = true;
        try {
          const res = await window.Commune.request(this.cfg.urls.createDraft, { method: 'POST', data: {} });
          this.load(res);
          this.toast(`Draft v${res.version.no} created from the live layout. Changes save automatically and go live when you publish.`, 'success');
        } catch (e) { this.handleError(e); } finally { this.busy = false; }
      },
      load(res) {
        this.draft = res.version.status === 'draft' ? { id: res.version.id, no: res.version.no, revision: res.version.revision, updated_at: res.version.updated_at, updated_by: res.version.updated_by } : null;
        this.doc = res.doc;
        this.pricing = res.pricing;
        this.undoStack = []; this.redoStack = []; this.sel = [];
        this.save = { state: 'saved', at: null, message: '' };
        this.render();
      },
      async reloadDraft() {
        this.dlg = null;
        try { this.load(await this.api('GET', `/versions/${this.draft.id}`)); } catch (e) { window.location.reload(); }
      },
      async preview() {
        await this.flush();
        window.open(`${this.cfg.urls.preview}${this.draft ? `?version=${this.draft.id}` : ''}`, '_blank', 'noopener');
      },
      async openPublish() {
        if (!this.draft) return;
        this.dlg = 'publish';
        this.check = null; this.confirmWarnings = false; this.checkBusy = true;
        try {
          if (!(await this.flush())) { this.dlg = null; return; }
          this.check = await this.api('GET', `/versions/${this.draft.id}/check`);
        } catch (e) { this.handleError(e); this.dlg = null; } finally { this.checkBusy = false; }
      },
      focusCode(code) {
        const s = this.doc.seats.find((x) => x.code === code.split(' ')[0]);
        if (!s) return;
        this.dlg = null;
        this.sel = [this.ref('s', s.parent_id ?? s.id)];
        this.render();
      },
      async publish() {
        this.busy = true;
        try {
          const res = await this.api('POST', `/versions/${this.draft.id}/publish`, { confirm: this.confirmWarnings, notes: this.publishNotes });
          this.save.state = 'saved';
          window.location.href = `${window.location.pathname}?published=${res.version.version_no}`;
        } catch (e) {
          if (e.body?.validation) this.check = e.body.validation;
          this.handleError(e);
        } finally { this.busy = false; }
      },
      async discard() {
        this.busy = true;
        try {
          await this.api('DELETE', `/versions/${this.draft.id}`, {});
          this.save.state = 'saved';
          window.location.href = window.location.pathname;
        } catch (e) { this.handleError(e); } finally { this.busy = false; }
      },

      // ------------------------------------------------------------ plumbing
      api(method, path, data = null) {
        return window.Commune.request(`${this.cfg.urls.api}${path}`, { method, data: method === 'GET' ? null : data });
      },
      handleError(e) {
        if (e.status === 419) { this.toast('Your session expired — reloading…', 'warning'); setTimeout(() => window.location.reload(), 1200); return; }
        if (e.status === 409 && e.body?.reason) { this.save.state = 'conflict'; this.save.message = e.body.message; this.dlg = 'conflict'; return; }
        this.toast(e.body?.message || (e.body?.errors ? Object.values(e.body.errors).flat()[0] : null) || e.message || 'Something went wrong.', e.status === 422 || e.status === 409 ? 'warning' : 'danger');
      },
      toast(msg, tone = 'info', action = null, ms = 4500) {
        const id = Math.random().toString(36).slice(2);
        this.toasts.push({ id, msg, tone, action });
        if (this.toasts.length > 3) this.toasts.shift();
        setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, ms);
      },
    }));

    // ================================================================== Building hotspot editor
    Alpine.data('hotspotEditor', () => ({
      cfg: {},
      floors: [],
      active: null,
      drag: null,
      state: 'saved',
      message: '',
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.floors = this.cfg.floors.map((f) => ({ id: f.id, name: f.name, level: f.level, hotspot: (f.hotspot || []).map((p) => [Number(p[0]), Number(p[1])]), closed: (f.hotspot || []).length >= 3 }));
        this.active = this.floors[0]?.id ?? null;
        window.addEventListener('pointermove', (e) => this.onMove(e));
        window.addEventListener('pointerup', () => { if (this.drag) { this.drag = null; this.touch(); } });
        window.addEventListener('beforeunload', (e) => { if (this.state === 'dirty') { e.preventDefault(); e.returnValue = ''; } });
      },
      get cur() { return this.floors.find((f) => f.id === this.active) || null; },
      pts(f) { return f.hotspot.map((p) => p.join(',')).join(' '); },
      toPct(e) {
        const r = this.$refs.photo.getBoundingClientRect();
        return [Math.round(clamp(((e.clientX - r.left) / r.width) * 100, 0, 100) * 100) / 100, Math.round(clamp(((e.clientY - r.top) / r.height) * 100, 0, 100) * 100) / 100];
      },
      /** click on the photo: add a point to the active floor's open polygon; click the first point to close */
      addPoint(e) {
        const f = this.cur;
        if (!f || this.drag) return;
        if (f.closed) { this.message = 'This floor’s shape is closed. Drag its points, Alt-click a point to delete it, or “Redraw”.'; return; }
        f.hotspot.push(this.toPct(e));
        this.touch();
      },
      pointDown(f, i, e) {
        e.stopPropagation();
        if (e.altKey || e.button === 2) { this.removePoint(f, i); return; }
        if (!f.closed && i === 0 && f.hotspot.length >= 3) { f.closed = true; this.touch(); return; }
        this.active = f.id;
        this.drag = { f, i };
      },
      onMove(e) {
        if (!this.drag) return;
        this.drag.f.hotspot[this.drag.i] = this.toPct(e);
      },
      removePoint(f, i) {
        f.hotspot.splice(i, 1);
        if (f.hotspot.length < 3) f.closed = false;
        this.touch();
      },
      close() { const f = this.cur; if (f && f.hotspot.length >= 3) { f.closed = true; this.touch(); } },
      redraw() { const f = this.cur; if (f) { f.hotspot = []; f.closed = false; this.touch(); } },
      touch() { this.state = 'dirty'; this.message = ''; },
      async saveAll() {
        const open = this.floors.find((f) => f.hotspot.length && !f.closed);
        if (open) { this.message = `Close the shape of ${open.name} first (click its first point).`; this.active = open.id; return; }
        this.state = 'saving';
        try {
          await window.Commune.request(this.cfg.saveUrl, { method: 'PUT', data: { floors: this.floors.map((f) => ({ id: f.id, name: f.name, level: f.level, hotspot: f.hotspot })) } });
          this.state = 'saved';
          this.message = 'Saved ✓ — the public building view uses these shapes now.';
        } catch (e) {
          this.state = 'dirty';
          this.message = e.body?.message || 'Could not save.';
        }
      },
      centreY(f) { const ys = f.hotspot.map((p) => p[1]); return ys.length ? (Math.min(...ys) + Math.max(...ys)) / 2 : 0; },
    }));
  });
})();
