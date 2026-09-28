/*
 * Finance console helpers (loaded per page, no inline scripts — CSP):
 *  - [data-select-all="ids[]"] checkbox toggles every checkbox with that name (bulk verify)
 *  - <canvas data-chart='{"type":"bar", ...}'> renders a Chart.js chart (vendored chart.umd.min.js must load first)
 *    payload: { kind: "monthly", labels, due, collected, current } | { kind: "hbar", labels, values }
 */
(() => {
  document.addEventListener('change', (event) => {
    const all = event.target.closest('[data-select-all]');
    if (!all) return;
    document.querySelectorAll(`input[type=checkbox][name="${all.dataset.selectAll}"]`).forEach((box) => {
      if (!box.disabled) box.checked = all.checked;
    });
  });

  const css = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
  const rupees = (v) => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const compact = (v) => {
    const n = Number(v);
    if (n >= 1e7) return '₹' + (n / 1e7).toFixed(1).replace(/\.0$/, '') + ' Cr';
    if (n >= 1e5) return '₹' + (n / 1e5).toFixed(1).replace(/\.0$/, '') + ' L';
    if (n >= 1e3) return '₹' + (n / 1e3).toFixed(0) + 'k';
    return '₹' + n;
  };

  function render(canvas) {
    if (!window.Chart || canvas.dataset.rendered) return;
    let cfg;
    try { cfg = JSON.parse(canvas.dataset.chart || '{}'); } catch { return; }
    canvas.dataset.rendered = '1';
    const brand = css('--color-brand-600', '#1d4ed8');
    const soft = css('--color-brand-100', '#dbe8fe');
    const muted = css('--color-muted', '#6b7280');
    const line = css('--color-line', '#e5e7eb');
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = muted;
    const tooltip = { callbacks: { label: (ctx) => ` ${ctx.dataset.label || ctx.label}: ${rupees(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed)}` } };
    if (cfg.kind === 'monthly') {
      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: cfg.labels,
          datasets: [
            { label: 'Due', data: cfg.due, backgroundColor: soft, borderRadius: 6, maxBarThickness: 26 },
            { label: 'Collected', data: cfg.collected, backgroundColor: cfg.labels.map((_, i) => (i === cfg.current ? css('--color-accent-500', '#e11d74') : brand)), borderRadius: 6, maxBarThickness: 26 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip },
          scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: line }, ticks: { callback: compact, maxTicksLimit: 5 } } },
        },
      });
    } else if (cfg.kind === 'hbar') {
      new Chart(canvas, {
        type: 'bar',
        data: { labels: cfg.labels, datasets: [{ label: cfg.label || 'Revenue', data: cfg.values, backgroundColor: brand, borderRadius: 6, maxBarThickness: 22 }] },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ` ${rupees(ctx.parsed.x)}` } } },
          scales: { x: { beginAtZero: true, grid: { color: line }, ticks: { callback: compact, maxTicksLimit: 5 } }, y: { grid: { display: false } } },
        },
      });
    }
  }

  const boot = () => document.querySelectorAll('canvas[data-chart]').forEach(render);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
