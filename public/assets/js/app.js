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


/**
 * KYC identifier checks — a mirror of App\Services\Kyc\IdValidator for instant feedback.
 * The server always re-validates; keep both in sync.
 */
window.Commune.kyc = (() => {
  const D = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
    [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
  const P = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],
    [9,4,5,3,1,2,7,8,6,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
  const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const norm = (v) => String(v ?? '').replace(/[\s-]+/g, '').toUpperCase();
  const verhoeff = (n) => { let c = 0; [...n].reverse().forEach((d, i) => { c = D[c][P[i % 8][+d]]; }); return c === 0; };
  const gstCheck = (s14) => {
    let sum = 0;
    [...s14].forEach((ch, i) => { const p = CHARS.indexOf(ch) * (i % 2 ? 2 : 1); sum += Math.floor(p / 36) + (p % 36); });
    return CHARS[(36 - (sum % 36)) % 36];
  };
  const rules = {
    aadhaar: (v) => { const n = norm(v); return /^[2-9]\d{11}$/.test(n) && verhoeff(n) ? '' : (/^\d{12}$/.test(n) ? 'This Aadhaar number fails the checksum — please re-check the digits.' : 'Enter the 12-digit Aadhaar number.'); },
    pan: (v) => (/^[A-Z]{3}[ABCFGHJLPT][A-Z]\d{4}[A-Z]$/.test(norm(v)) ? '' : 'PAN format is AAAAA9999A (e.g. ABCPE1234F).'),
    tan: (v) => (/^[A-Z]{4}\d{5}[A-Z]$/.test(norm(v)) ? '' : 'TAN format is AAAA99999A.'),
    passport: (v) => (/^[A-Z0-9]{6,12}$/.test(norm(v)) ? '' : 'Use 6–12 letters and digits.'),
    mobile: (v) => (/^(?:\+?91|0)?[6-9]\d{9}$/.test(String(v ?? '').replace(/[\s\-()]+/g, '')) ? '' : 'Enter a 10-digit Indian mobile number (+91 optional).'),
    gstin: (v, ctx = {}) => {
      const g = norm(v);
      if (!/^\d{2}[A-Z]{3}[ABCFGHJLPT][A-Z]\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(g)) return 'GSTIN is 15 characters, e.g. 32ABCPE1234F1ZK.';
      if (gstCheck(g.slice(0, 14)) !== g[14]) return 'The GSTIN check character is wrong — please re-check it.';
      if (ctx.pan && norm(ctx.pan) && g.slice(2, 12) !== norm(ctx.pan)) return 'This GSTIN does not contain the PAN entered above.';
      if (ctx.state && g.slice(0, 2) !== String(ctx.state).padStart(2, '0')) return 'The GSTIN state code does not match the selected state.';
      return '';
    },
  };
  return { norm, verhoeff, gstCheck, rules, check: (kind, v, ctx) => (rules[kind] ? rules[kind](v, ctx) : '') };
})();

const isoDate = (d) => {
  const off = d.getTimezoneOffset();
  return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
};

document.addEventListener('alpine:init', () => {
  const Alpine = window.Alpine;

  /** Seat-hold countdown (checkout page): x-data="holdTimer(540)"; exposes text, low, expired. */
  Alpine.data('holdTimer', (seconds = 0) => ({
    left: Math.max(0, Number(seconds) || 0),
    init() {
      const end = Date.now() + this.left * 1000;
      const t = setInterval(() => {
        this.left = Math.max(0, Math.round((end - Date.now()) / 1000));
        if (this.left === 0) clearInterval(t);
      }, 1000);
    },
    get text() { return `${String(Math.floor(this.left / 60)).padStart(2, '0')}:${String(this.left % 60).padStart(2, '0')}`; },
    get low() { return this.left > 0 && this.left <= 60; },
    get expired() { return this.left === 0; },
  }));

  /** Hero "Check availability" bar: keeps "to" >= "from" and defaults to a 1-month range. */
  Alpine.data('availabilityBar', (from = '', to = '') => ({
    from: from || isoDate(new Date()),
    to: to || isoDate(new Date(Date.now() + 29 * 864e5)),
    today: isoDate(new Date()),
    fromChanged() {
      if (this.to < this.from) this.to = this.from;
    },
  }));


  /**
   * Live KYC field check. Wrap an input component:
   *   <div x-data="kycCheck('pan')" @input="check($event.target.value)" @focusout="check($event.target.value, true)"> … </div>
   * opts: { pan: 'input[name=pan]', state: 'select[name=state_code]', optional: true }
   */
  Alpine.data('kycCheck', (kind, opts = {}) => ({
    msg: '',
    ok: false,
    touched: false,
    init() {
      const input = this.$el.querySelector('input');
      if (input && input.value) this.check(input.value, true);
    },
    check(value, blur = false) {
      if (blur) this.touched = true;
      const v = String(value ?? '').trim();
      if (!v) { this.msg = ''; this.ok = false; return; }
      const ctx = {
        pan: opts.pan ? document.querySelector(opts.pan)?.value : '',
        state: opts.state ? document.querySelector(opts.state)?.value : '',
      };
      const err = window.Commune.kyc.check(kind, v, ctx);
      this.ok = err === '';
      // Show errors once the field was left, or immediately when the value has full length.
      const full = { aadhaar: 12, pan: 10, tan: 10, gstin: 15 }[kind];
      this.msg = err && (this.touched || (full && window.Commune.kyc.norm(v).length >= full)) ? err : '';
    },
  }));

  /** Password strength meter (0-4) for set/reset password forms. */
  Alpine.data('passwordStrength', () => ({
    pw: '',
    confirm: '',
    get checks() {
      const p = this.pw;
      return [
        { label: 'At least 8 characters', ok: p.length >= 8 },
        { label: 'Upper & lower case letters', ok: /[a-z]/.test(p) && /[A-Z]/.test(p) },
        { label: 'A number', ok: /\d/.test(p) },
        { label: 'A symbol (e.g. ! @ # %)', ok: /[^A-Za-z0-9]/.test(p) },
      ];
    },
    get score() {
      if (!this.pw) return 0;
      let s = this.checks.filter((c) => c.ok).length;
      if (this.pw.length >= 12 && s === 4) s = 5;
      return Math.min(4, Math.max(1, s - (this.pw.length < 8 ? 1 : 0)));
    },
    get label() { return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.score]; },
    get valid() { return this.checks.every((c) => c.ok); },
    get matches() { return this.confirm === '' || this.confirm === this.pw; },
  }));

  /**
   * KYC document picker: drag & drop, file dialog, phone camera (capture attr) and optional webcam
   * (getUserMedia) with an instant preview. The chosen file stays in the real <input type=file>
   * (x-ref="input"), so the surrounding form submits it normally.
   */
  Alpine.data('docUpload', (opts = {}) => ({
    file: null,
    name: '',
    size: '',
    preview: null,
    isPdf: false,
    dragging: false,
    error: '',
    camera: false,
    stream: null,
    maxBytes: 5 * 1024 * 1024,
    types: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
    pick(e) { const f = e.target.files[0]; if (f) this.use(f, false); },
    drop(e) {
      this.dragging = false;
      const f = e.dataTransfer?.files?.[0];
      if (f) this.use(f, true);
    },
    use(f, assign) {
      this.error = '';
      if (!this.types.includes(f.type)) { this.error = 'Only PDF, JPG, PNG or WebP files.'; this.reset(); return; }
      if (f.size > this.maxBytes) { this.error = 'The file is larger than 5 MB.'; this.reset(); return; }
      if (assign) { const dt = new DataTransfer(); dt.items.add(f); this.$refs.input.files = dt.files; }
      this.file = f;
      this.name = f.name;
      this.size = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
      this.isPdf = f.type === 'application/pdf';
      if (this.preview) URL.revokeObjectURL(this.preview);
      this.preview = this.isPdf ? null : URL.createObjectURL(f);
      if (opts.autoSubmit) this.$nextTick(() => this.$refs.input.form?.requestSubmit());
    },
    reset() {
      this.file = null; this.name = ''; this.size = '';
      if (this.preview) URL.revokeObjectURL(this.preview);
      this.preview = null;
      if (this.$refs.input) this.$refs.input.value = '';
    },
    get hasWebcam() { return !!navigator.mediaDevices?.getUserMedia; },
    async startCamera() {
      this.error = '';
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 } }, audio: false });
        this.camera = true;
        this.$nextTick(() => { this.$refs.video.srcObject = this.stream; this.$refs.video.play(); });
      } catch (err) {
        this.error = 'Camera unavailable: ' + (err?.message || 'permission denied');
      }
    },
    snap() {
      const v = this.$refs.video;
      const c = document.createElement('canvas');
      c.width = v.videoWidth; c.height = v.videoHeight;
      c.getContext('2d').drawImage(v, 0, 0);
      c.toBlob((blob) => {
        if (!blob) return;
        this.use(new File([blob], `capture-${Date.now()}.jpg`, { type: 'image/jpeg' }), true);
        this.stopCamera();
      }, 'image/jpeg', 0.9);
    },
    stopCamera() {
      this.stream?.getTracks().forEach((t) => t.stop());
      this.stream = null;
      this.camera = false;
    },
    destroy() { this.stopCamera(); },
  }));

  /**
   * Staff assisted registration: asks the server for possible existing visitors whenever an
   * identifying field (email, mobile, Aadhaar, PAN, GSTIN) changes. Usage on the <form>:
   *   x-data="duplicateCheck('/staff/visitors/duplicates', 0)" @change="lookup($event)"
   */
  Alpine.data('duplicateCheck', (url, exclude = 0, nat = 'indian') => ({
    matches: [],
    nat, // nationality toggle mirrored for the document list (Aadhaar vs passport slot)
    busy: false,
    timer: null,
    fields: ['email', 'mobile', 'aadhaar', 'sig_aadhaar', 'pan', 'gstin'],
    lookup(e) {
      if (e.target?.name === 'nationality_type') this.nat = e.target.value;
      if (!this.fields.includes(e.target?.name)) return;
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.run(), 250);
    },
    async run() {
      const data = { exclude };
      this.fields.forEach((f) => { const el = this.$root.querySelector(`[name="${f}"]`); if (el && el.value) data[f] = el.value; });
      this.busy = true;
      try {
        this.matches = (await window.Commune.request(url, { method: 'POST', data })).matches || [];
      } catch { /* the server re-checks on submit */ } finally { this.busy = false; }
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
