/**
 * Shared SVG renderer for seat maps — used by the Space Explorer (explorer.js, visitors + reception) and the
 * Layout & Pricing Designer (designer.js), so both draw zones, seats, cabins/rooms and facilities identically.
 * Source: resources/js/space-render.js → public/assets/js/space-render.js (npm run vendor:js).
 * Load it (defer) BEFORE explorer.js / designer.js and alpine.min.js.
 *
 * Geometry is in PERCENT of the floor image (x/y = top-left, w/h = size, rotation in degrees around the
 * seat centre); `geo` = { W, H } is the image size in px, used as the SVG viewBox.
 *
 *   const R = window.CommuneSpace;
 *   R.defs(svg);                                   // stripe patterns used by occupied seats
 *   R.zonePolygon(layer, zone, geo)                // tinted dashed polygon
 *   R.zoneTag(layer, zone, geo, { title, sub })    // white pill on the zone's top wall
 *   R.unitBlock(layer, unit, geo, { line1, line2, hourly })  // cabin / room outline + tag
 *   R.chair(layer, seat, geo, { child })           // rounded seat with armchair glyph + number
 *   R.facility(layer, placement, geo)              // round facility / landmark marker
 * Icons are <use href="#i-name"> from partials/space/sprite.
 */
(() => {
  const NS = 'http://www.w3.org/2000/svg';
  const UNIT_SHORT = { day: 'day', month: 'mo', hour: 'hr', use: 'use' };
  const money = (n, d = 0) => window.Commune.formatINR(Number(n || 0), d);

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
  const px = (geo, p) => (p / 100) * geo.W;
  const py = (geo, p) => (p / 100) * geo.H;
  const points = (geo, poly) => poly.map(([x, y]) => `${px(geo, x)},${py(geo, y)}`).join(' ');
  const rectPoly = (r) => [[r.x, r.y], [r.x + r.w, r.y], [r.x + r.w, r.y + r.h], [r.x, r.y + r.h]];

  function defs(svg) {
    const d = el('defs', {}, svg);
    const pat = el('pattern', { id: 'sx-stripes', width: 10, height: 10, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, d);
    el('rect', { width: 5, height: 10, fill: 'rgba(255,255,255,.55)' }, pat);
    const pat2 = el('pattern', { id: 'sx-stripes-soft', width: 14, height: 14, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, d);
    el('rect', { width: 14, height: 14, fill: 'rgba(254,226,226,.55)' }, pat2);
    el('rect', { width: 5, height: 14, fill: 'rgba(239,68,68,.16)' }, pat2);
    return d;
  }

  /** Zone tint. zone: { id, polygon?, rect|{x,y,w,h}, colour } */
  function zonePolygon(layer, z, geo, attrs = {}) {
    const colour = z.colour || '#94a3b8';
    const poly = z.polygon && z.polygon.length >= 3 ? z.polygon : rectPoly(z.rect || z);
    return el('polygon', { class: 'sx-zone', 'data-zone': z.id, points: points(geo, poly), fill: colour, stroke: colour, ...attrs }, layer);
  }

  /** Pill straddling the zone's top wall; returns { g, sub } (sub = the second text line, for live counts). */
  function zoneTag(layer, z, geo, { title, sub = '', colour = null }) {
    const r = z.rect || z;
    const w = Math.max(title.length * 11.6, sub.length * 9.6) + 64;
    const x = px(geo, r.x + r.w / 2) - w / 2;
    const y = Math.max(4, py(geo, r.y) - 34);
    const g = el('g', { class: 'sx-zone-tag', transform: `translate(${x},${y})`, 'data-ztag': z.id }, layer);
    el('rect', { width: w, height: 64, rx: 32 }, g);
    el('circle', { class: 'dot', cx: 32, cy: 32, r: 9, fill: colour || z.colour || '#94a3b8' }, g);
    el('text', { x: 52, y: 29 }, g).textContent = title;
    const t2 = el('text', { x: 52, y: 51, class: 'sub' }, g);
    t2.textContent = sub;
    return { g, sub: t2 };
  }

  /** Cabin / conference room: dashed outline + dark tag. The caller binds events on the returned <g>. */
  function unitBlock(layer, u, geo, { line1, line2 = '', hourly = false, attrs = {} }) {
    const g = el('g', { class: 'sx-unit', 'data-id': u.id, 'data-status': u.status || 'available', ...attrs }, layer);
    el('rect', { class: 'outline', x: px(geo, u.x), y: py(geo, u.y), width: px(geo, u.w), height: py(geo, u.h), rx: 18 }, g);
    const tw = Math.max(40, Math.min(px(geo, u.w) - 12, Math.max(line1.length * 11.5, line2.length * 8.6) + 56));
    const tx = px(geo, u.x + u.w / 2) - tw / 2;
    // cabins: inside, near the bottom; the conference room: straddling its bottom wall (chairs fill the room)
    const ty = hourly ? py(geo, u.y + u.h) - 22 : py(geo, u.y + u.h) - 70;
    const tag = el('g', { class: 'tag', transform: `translate(${tx},${ty})` }, g);
    el('rect', { width: tw, height: 58, rx: 18 }, tag);
    glyph(hourly ? 'clock' : 'door-open', { x: 12, y: 17, width: 24, height: 24, style: 'color:#fff' }, tag);
    el('text', { x: 44, y: 26 }, tag).textContent = line1;
    el('text', { x: 44, y: 46, class: 'sub' }, tag).textContent = line2;
    return g;
  }

  /** transform for a seat: translate to its top-left, rotate around its centre */
  function seatTransform(s, geo) {
    const x = px(geo, s.x); const y = py(geo, s.y);
    const r = Number(s.rotation || 0);
    return r ? `translate(${x},${y}) rotate(${r},${px(geo, s.w) / 2},${py(geo, s.h) / 2})` : `translate(${x},${y})`;
  }

  /** Rounded seat body + armchair glyph + number. opts.child = chair of a cabin/room (not focusable). */
  function chair(layer, s, geo, { child = false, attrs = {} } = {}) {
    const w = px(geo, s.w); const h = py(geo, s.h);
    const g = el('g', {
      class: 'sx-seat', 'data-id': s.id, transform: seatTransform(s, geo),
      ...(child ? { 'aria-hidden': 'true' } : { role: 'button', tabindex: -1 }), ...attrs,
    }, layer);
    const pop = el('g', { class: 'pop' }, g);
    el('rect', { class: 'ring', x: -5, y: -5, width: w + 10, height: h + 10, rx: 16 }, pop);
    el('rect', { class: 'body', width: w, height: h, rx: Math.min(14, w * 0.28) }, pop);
    el('rect', { class: 'stripes', width: w, height: h, rx: Math.min(14, w * 0.28) }, pop);
    const small = w < 48;
    const gs = small ? w * 0.56 : w * 0.46;
    glyph('armchair', { class: 'glyph', x: (w - gs) / 2, y: small ? (h - gs) / 2 : h * 0.14, width: gs, height: gs }, pop);
    if (!small) el('text', { class: 'num', x: w / 2, y: h - 8 }, pop).textContent = s.label || '';
    return g;
  }

  /** Round facility marker: included (green ring), add-on (brand, + badge), landmark (dark). */
  function facility(layer, f, geo, attrs = {}) {
    const g = el('g', { class: 'sx-fac', 'data-kind': f.kind, transform: `translate(${px(geo, f.x)},${py(geo, f.y)})`, tabindex: -1, ...attrs }, layer);
    el('circle', { r: 19 }, g);
    glyph(f.icon || 'info', { x: -11, y: -11, width: 22, height: 22 }, g);
    if (f.kind === 'addon') el('circle', { class: 'plus', cx: 14, cy: -14, r: 6 }, g);
    el('title', {}, g).textContent = f.name;
    return g;
  }

  /**
   * Size the pan/zoom stage to the image aspect inside its viewport (shared map sizing).
   * opts.fill = true: the viewport height is fixed by CSS (designer) and the stage is fitted inside it
   * (opts.pad = margin in px around it).
   */
  function fitStage(vp, stage, W, H, opts = {}) {
    if (!vp || !stage || !vp.clientWidth) return;
    const aspect = W / H;
    const vw = vp.clientWidth;
    if (opts.fill) {
      const pad = opts.pad || 0;
      const vh = vp.clientHeight;
      let sw = vw - 2 * pad; let sh = sw / aspect;
      if (sh > vh - 2 * pad) { sh = vh - 2 * pad; sw = sh * aspect; }
      stage.style.width = `${sw}px`;
      stage.style.height = `${sh}px`;
      stage.style.left = `${(vw - sw) / 2}px`;
      stage.style.top = `${(vh - sh) / 2}px`;
      return;
    }
    const mobile = window.innerWidth < 768;
    let vh = mobile ? Math.max(340, Math.round(window.innerHeight * 0.58)) : Math.round(vw / aspect);
    if (!mobile) vh = Math.min(vh, Math.round(window.innerHeight * 0.78));
    vp.style.height = `${vh}px`;
    let sw = vw; let sh = vw / aspect;
    if (sh < vh) { sh = vh; sw = vh * aspect; }
    stage.style.width = `${sw}px`;
    stage.style.height = `${sh}px`;
  }

  window.CommuneSpace = {
    NS, UNIT_SHORT, money, el, glyph, priceText, fromPrice, px, py, points, rectPoly, seatTransform,
    defs, zonePolygon, zoneTag, unitBlock, chair, facility, fitStage,
  };
})();
