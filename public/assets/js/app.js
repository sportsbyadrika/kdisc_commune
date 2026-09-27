/**
 * Commune front-end bootstrap (plain ES2020, no bundler).
 * Source: resources/js/app.js  ->  copied to public/assets/js/app.js by `npm run vendor:js`.
 * Loaded with `defer` BEFORE alpine.min.js, so Alpine.data() registrations below are ready.
 *
 * Conventions:
 *  - Register reusable components with Alpine.data('name', () => ({...})) and use x-data="name".
 *  - For fetch() POSTs send the CSRF token: headers: { 'X-CSRF-TOKEN': Commune.csrf() }.
 */
window.Commune = {
  csrf: () => document.querySelector('meta[name="csrf-token"]')?.content ?? '',

  /** JSON fetch helper with CSRF + error handling. */
  async request(url, { method = 'GET', data = null } = {}) {
    const res = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(data ? { 'Content-Type': 'application/json' } : {}),
        ...(method !== 'GET' ? { 'X-CSRF-TOKEN': window.Commune.csrf() } : {}),
      },
      body: data ? JSON.stringify(data) : null,
      credentials: 'same-origin',
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(body.message || res.statusText), { status: res.status, body });
    return body;
  },

  formatINR(amount, decimals = 0) {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: decimals, minimumFractionDigits: decimals }).format(amount);
  },
};

const isoDate = (d) => {
  const off = d.getTimezoneOffset();
  return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
};

document.addEventListener('alpine:init', () => {
  const Alpine = window.Alpine;

  /** Hero "Check availability" bar: keeps "to" >= "from" and defaults to a 1-month range. */
  Alpine.data('availabilityBar', (from = '', to = '') => ({
    from: from || isoDate(new Date()),
    to: to || isoDate(new Date(Date.now() + 29 * 864e5)),
    today: isoDate(new Date()),
    fromChanged() {
      if (this.to < this.from) this.to = this.from;
    },
  }));

  /** Staff console sidebar, collapsed state remembered per browser. */
  Alpine.store('sidebar', {
    collapsed: (() => { try { return localStorage.getItem('commune.sidebar') === '1'; } catch { return false; } })(),
    mobileOpen: false,
    toggle() {
      this.collapsed = !this.collapsed;
      try { localStorage.setItem('commune.sidebar', this.collapsed ? '1' : '0'); } catch { /* storage unavailable */ }
    },
  });

  /** Filterable grid: x-data="filterable('all')" + chips component with model 'filter'. */
  Alpine.data('filterable', (initial = 'all') => ({
    filter: initial,
    shows(value) {
      return this.filter === 'all' || this.filter === value;
    },
  }));
});
