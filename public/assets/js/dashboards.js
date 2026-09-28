/*
 * Dashboards & reports (State Admin / Centre Manager dashboards, /staff/reports/*). Loaded per page, no inline
 * scripts (CSP). Needs space-render.js (heat-maps) and the vendored chart.umd.min.js (charts) loaded first.
 *
 *   heatMap (Alpine)  read-only floor map from DashboardService::heatmaps(): every unit coloured by its occupancy %
 *                     over the period — sequential blue ramp --color-heat-1..5 (0 % = dashed "unused", blocked = grey
 *                     stripes); hover / focus shows a tooltip with seat-days.
 *   <canvas data-dash-chart='{...}'>   Chart.js chart. kinds:
 *       line-pct      one series of percentages (occupancy trend), crosshair-style index tooltip
 *       trend-money   two ₹ series as grouped bars (net revenue invoiced vs collected) + legend
 *       bar-money | bar-count | hbar | hbar-count     one series (the card title names it; no legend)
 *       funnel        horizontal bars, stages shaded with the ordinal ramp (dark = first stage)
 */
(() => {
  const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
  const inr = (v, d = 0) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: d, minimumFractionDigits: d });
  const compact = (v) => {
    const n = Number(v);
    if (Math.abs(n) >= 1e7) return '₹' + (n / 1e7).toFixed(1).replace(/\.0$/, '') + ' Cr';
    if (Math.abs(n) >= 1e5) return '₹' + (n / 1e5).toFixed(1).replace(/\.0$/, '') + ' L';
    if (Math.abs(n) >= 1e3) return '₹' + (n / 1e3).toFixed(0) + 'k';
    return '₹' + n;
  };

  // ================================================================== heat-map
  document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;
    const R = window.CommuneSpace;

    Alpine.data('heatMap', () => ({
      cfg: {},
      tip: { show: false, x: 0, y: 0, title: '', line: '', sub: '' },
      init() {
        this.cfg = JSON.parse(this.$el.dataset.config || '{}');
        this.$nextTick(() => this.draw());
      },
      bin(h) {
        if (!h) return '0';
        if (h.blocked) return 'x';
        if (h.pct <= 0) return '0';
        const bins = this.cfg.bins || [20, 40, 60, 80, 100];
        for (let i = 0; i < bins.length; i++) if (h.pct <= bins[i]) return String(i + 1);
        return String(bins.length);
      },
      draw() {
        const svg = this.$refs.svg;
        if (!svg || !R) return;
        const { width: W, height: H } = this.cfg.floor;
        const geo = { W, H };
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        svg.innerHTML = '';
        R.defs(svg);
        R.el('image', { href: this.cfg.floor.image, x: 0, y: 0, width: W, height: H, preserveAspectRatio: 'none', opacity: 0.55 }, svg);
        const zl = R.el('g', {}, svg); const ul = R.el('g', {}, svg); const sl = R.el('g', {}, svg);
        for (const z of this.cfg.zones) R.zonePolygon(zl, { ...z, colour: '#94a3b8' }, geo, { 'fill-opacity': 0.04, 'stroke-opacity': 0.35 }); // neutral zones: colour = occupancy only
        const heat = this.cfg.heat || {};
        const units = this.cfg.seats.filter((s) => s.parent === null && s.category);
        const chairsOf = {};
        for (const s of this.cfg.seats) if (s.parent) (chairsOf[s.parent] ||= []).push(s);
        for (const u of units.filter((s) => s.kind !== 'seat')) {
          const h = heat[u.key];
          const b = this.bin(h);
          const g = R.unitBlock(ul, u, geo, { line1: u.label, line2: this.pctText(h), hourly: u.category === 'CONF' });
          g.dataset.heat = b;
          this.bind(g, u, h);
          for (const c of chairsOf[u.id] || []) { const cg = R.chair(sl, c, geo, { child: true }); cg.dataset.heat = b; this.hover(cg, g, u, h); }
        }
        for (const u of units.filter((s) => s.kind === 'seat')) {
          const h = heat[u.key];
          const g = R.chair(sl, u, geo);
          g.dataset.heat = this.bin(h);
          this.bind(g, u, h);
        }
      },
      pctText(h) {
        if (!h) return 'no data';
        if (h.blocked) return 'blocked';
        return `${Number(h.pct).toLocaleString('en-IN', { maximumFractionDigits: 1 })}% occupied`;
      },
      bind(g, u, h) {
        g.setAttribute('tabindex', '0');
        g.setAttribute('role', 'img');
        this.hover(g, g, u, h);
      },
      /** tooltip on hover / focus of `target`, highlighting `unitEl` (a cabin's chairs report their cabin) */
      hover(target, unitEl, u, h) {
        const g = target;
        const name = u.kind === 'seat' ? `Seat ${u.code}` : `${u.label} (${u.code})`;
        const detail = h && !h.blocked ? `${h.occ} of ${h.cap} seat-days` : (h && h.blocked ? 'Blocked for the whole period' : 'Not in the period');
        if (g === unitEl) g.setAttribute('aria-label', `${name}: ${this.pctText(h)}, ${detail}`);
        const show = (ev) => {
          const box = this.$el.getBoundingClientRect();
          const r = g.getBoundingClientRect();
          const x = ev && ev.clientX ? ev.clientX : r.left + r.width / 2;
          const y = ev && ev.clientY ? ev.clientY : r.top;
          this.tip = { show: true, x: Math.min(box.width - 150, Math.max(8, x - box.left + 12)), y: Math.max(8, y - box.top - 56), title: name, line: this.pctText(h), sub: detail };
          unitEl.classList.add('is-hover');
        };
        const hide = () => { this.tip.show = false; unitEl.classList.remove('is-hover'); };
        g.addEventListener('pointermove', show);
        g.addEventListener('pointerleave', hide);
        g.addEventListener('focus', () => show(null));
        g.addEventListener('blur', hide);
      },
    }));
  });

  // ================================================================== charts
  function render(canvas) {
    if (!window.Chart || canvas.dataset.rendered) return;
    let cfg;
    try { cfg = JSON.parse(canvas.dataset.dashChart || '{}'); } catch { return; }
    canvas.dataset.rendered = '1';
    const s1 = css('--color-chart-1', '#1d4ed8');
    const s2 = css('--color-chart-2', '#eb6834');
    const muted = css('--color-muted', '#6b7280');
    const line = css('--color-line', '#e5e7eb');
    const ink = css('--color-ink', '#111827');
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = muted;
    const base = {
      responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
      plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: 'rgba(7,15,38,.95)', titleColor: '#fff', bodyColor: '#fff', padding: 10, cornerRadius: 10, displayColors: false },
      },
    };
    const grid = { color: line, drawTicks: false };
    const kind = cfg.kind;
    const ramp = [5, 4, 3, 2, 1].map((n) => css(`--color-heat-${n}`, '#2a78d6'));

    if (kind === 'line-pct') {
      new Chart(canvas, {
        type: 'line',
        data: { labels: cfg.labels, datasets: [{ label: cfg.label || 'Occupancy', data: cfg.values, borderColor: s1, backgroundColor: s1, borderWidth: 2, pointRadius: cfg.values.length > 40 ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: '#fff', pointBorderWidth: 2, tension: 0.25, fill: false }] },
        options: {
          ...base,
          interaction: { mode: 'index', intersect: false },
          plugins: { ...base.plugins, tooltip: { ...base.plugins.tooltip, callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y.toLocaleString('en-IN', { maximumFractionDigits: 1 })}%` } } },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
            y: { beginAtZero: true, suggestedMax: 100, max: 100, grid, border: { display: false }, ticks: { callback: (v) => v + '%', maxTicksLimit: 5, padding: 6 } },
          },
        },
      });
    } else if (kind === 'trend-money') {
      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: cfg.labels,
          datasets: [
            { label: cfg.labelA || 'Net revenue invoiced', data: cfg.a, backgroundColor: s1, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 18, categoryPercentage: 0.7, barPercentage: 0.9 },
            { label: cfg.labelB || 'Collected', data: cfg.b, backgroundColor: s2, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 18, categoryPercentage: 0.7, barPercentage: 0.9 },
          ],
        },
        options: {
          ...base,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            ...base.plugins,
            legend: { display: true, position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10, boxHeight: 10, color: ink, padding: 14 } },
            tooltip: { ...base.plugins.tooltip, displayColors: true, callbacks: { label: (c) => ` ${c.dataset.label}: ${inr(c.parsed.y)}` } },
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
            y: { beginAtZero: true, grid, border: { display: false }, ticks: { callback: compact, maxTicksLimit: 5, padding: 6 } },
          },
        },
      });
    } else if (kind === 'bar-money' || kind === 'bar-count') {
      const money = kind === 'bar-money';
      new Chart(canvas, {
        type: 'bar',
        data: { labels: cfg.labels, datasets: [{ label: cfg.label || '', data: cfg.values, backgroundColor: s1, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', maxBarThickness: 36 }] },
        options: {
          ...base,
          plugins: { ...base.plugins, tooltip: { ...base.plugins.tooltip, callbacks: { label: (c) => `${cfg.label || ''}: ${money ? inr(c.parsed.y) : c.parsed.y.toLocaleString('en-IN')}` } } },
          scales: {
            x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
            y: { beginAtZero: true, grid, border: { display: false }, ticks: { callback: money ? compact : (v) => v, maxTicksLimit: 5, precision: 0, padding: 6 } },
          },
        },
      });
    } else if (kind === 'hbar' || kind === 'hbar-count' || kind === 'funnel') {
      const money = kind === 'hbar';
      const colors = kind === 'funnel' ? cfg.values.map((_, i) => ramp[Math.min(i, ramp.length - 1)]) : s1;
      new Chart(canvas, {
        type: 'bar',
        data: { labels: cfg.labels, datasets: [{ label: cfg.label || '', data: cfg.values, backgroundColor: colors, borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'left', maxBarThickness: 22 }] },
        options: {
          ...base,
          indexAxis: 'y',
          plugins: { ...base.plugins, tooltip: { ...base.plugins.tooltip, callbacks: { label: (c) => `${cfg.label || ''}: ${money ? inr(c.parsed.x) : c.parsed.x.toLocaleString('en-IN')}` } } },
          scales: {
            x: { beginAtZero: true, grid, border: { display: false }, ticks: { callback: money ? compact : (v) => v, maxTicksLimit: 5, precision: 0 } },
            y: { grid: { display: false }, ticks: { color: ink } },
          },
        },
      });
    }
  }

  const boot = () => document.querySelectorAll('canvas[data-dash-chart]').forEach(render);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  // report filters: show the custom date fields only for "Custom dates"
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('select[data-range-select]');
    if (!sel) return;
    const box = document.getElementById(sel.dataset.rangeSelect);
    if (box) box.hidden = sel.value !== 'custom';
  });
})();
