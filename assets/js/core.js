/* TrakrHub Ads Manager - core (helpers, api, state, account/date pickers, modal, table, router) */
window.A = (() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---------- icons (lucide-style) ----------
  const I = {
    code: '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
    db: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    line: '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
    bars: '<path d="M3 3v18h18"/><path d="M8 17V9"/><path d="M13 17V5"/><path d="M18 17v-3"/>',
    auto: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>',
    case: '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    chev: '<path d="m9 18 6-6-6-6"/>', back: '<path d="m15 18-6-6 6-6"/>', down: '<path d="m6 9 6 6 6-6"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
    dl: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    rupee: '<path d="M6 3h12M6 8h12M6 13l8.5 8M6 13h3M9 13c6.667 0 6.667-10 0-10"/>',
    dollar: '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    mouse: '<rect x="5" y="2" width="14" height="20" rx="7"/><path d="M12 6v4"/>',
    pie: '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
    barchart: '<path d="M12 20V10M18 20V4M6 20v-4"/>',
    funnel: '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
    target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    trend: '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    dots: '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    pause: '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
    play: '<path d="m6 3 14 9-14 9V3z"/>',
    trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
    bulb: '<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    spark: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>',
    gauge: '<path d="M12 14l4-4"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    phone: '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
    monitor: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    tag: '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
    wallet: '<path d="M20 7H5a2 2 0 0 1 0-4h13v4"/><path d="M3 5v14a2 2 0 0 0 2 2h15V7"/><path d="M16 14h.01"/>',
    ad: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 15l2.5-6 2.5 6M7.8 13h3.4M15 9v6h1.5a3 3 0 0 0 0-6z"/>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
    globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
  };
  const icon = (n, cls = '') => `<svg class="i ${cls}" viewBox="0 0 24 24">${I[n] || ''}</svg>`;
  function icons(root = document) {
    $$('svg[data-i]', root).forEach(s => { s.setAttribute('viewBox', '0 0 24 24'); s.innerHTML = I[s.dataset.i] || ''; s.removeAttribute('data-i'); });
  }

  // ---------- formatting ----------
  const state = {
    accounts: [], acc: null, from: null, to: null, range: '7', demo: false, demoLabels: false, view: 'dashboard', report: null,
  };
  const num = n => new Intl.NumberFormat('en-IN').format(Math.round(n || 0));
  const dec = (n, p = 2) => new Intl.NumberFormat('en-IN', { minimumFractionDigits: p, maximumFractionDigits: p }).format(n || 0);
  const cur = () => state.report?.currency || state.acc?.currency || 'INR';
  const money = (n, full) => {
    try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: cur(), maximumFractionDigits: !full && Math.abs(n) >= 1000 ? 0 : 2 }).format(n || 0); }
    catch { return cur() + ' ' + dec(n); }
  };
  const fmtId = id => String(id || '').replace(/(\d{3})(\d{3})(\d+)/, '$1-$2-$3');
  const label = s => String(s || '').replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase());
  const derive = r => ({ ...r,
    ctr: r.impr ? r.clicks / r.impr * 100 : 0, cpc: r.clicks ? r.cost / r.clicks : 0,
    cpa: r.conv ? r.cost / r.conv : 0, roas: r.cost ? r.value / r.cost : 0 });
  const sum = rows => derive(rows.reduce((a, c) => { ['impr', 'clicks', 'cost', 'conv', 'value'].forEach(k => a[k] += +c[k] || 0); return a; }, { impr: 0, clicks: 0, cost: 0, conv: 0, value: 0 }));
  const statusPill = s => `<span class="status st-${esc(s)}">${esc(s === 'ENABLED' ? 'Active' : label(s))}</span>`;
  const roasCell = r => !r.cost ? '—' : `<span class="${r.roas >= 2 ? 'good' : r.roas < 1 ? 'bad' : ''}">${dec(r.roas)}x</span>`;

  // ---------- feedback ----------
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.classList.remove('hidden');
    clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.add('hidden'), 3000);
  }
  function showError(e) {
    const b = $('#errBox');
    if (!e) { b.classList.add('hidden'); return; }
    b.innerHTML = `<b>Error:</b> ${esc(e.error || e.message || e)}${e.hint ? `<br>💡 ${esc(e.hint)}` : ''}`;
    b.classList.remove('hidden');
    b.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ---------- API ----------
  async function api(action, params = {}, body = null) {
    const q = new URLSearchParams({ action, ...params });
    const opt = { credentials: 'same-origin' };
    if (body) { opt.method = 'POST'; opt.headers = { 'Content-Type': 'application/json', 'X-CSRF': APP.csrf }; opt.body = JSON.stringify(body); }
    const res = await fetch('api.php?' + q, opt);
    if (res.status === 401) { location.reload(); throw { error: 'Session expired' }; }
    const raw = await res.text();
    let data;
    try { data = JSON.parse(raw); } catch {
      // Not JSON: show part of the real response so the cause is clear
      const txt = raw.replace(/<style[\s\S]*?<\/style>|<script[\s\S]*?<\/script>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
      const why = res.status === 403 ? 'The server/firewall blocked the request (403). Check ModSecurity/WAF on Hostinger.'
        : res.status === 404 ? 'api.php not found (404). Check that the files were uploaded.'
        : res.status >= 500 ? `Server error (${res.status}). Check the PHP error log.` : `HTTP ${res.status}`;
      data = { error: `Could not read the server response [${action}]: ${why}${txt ? ' — ' + txt : ' — (empty response)'}` };
    }
    if (!res.ok || data.error) throw data;
    return data;
  }
  /** Call for the current account */
  const accParams = (extra = {}) => ({ conn: state.acc?.conn, cid: state.acc?.id, from: state.from, to: state.to, ...extra });
  const get = (action, extra) => api(action, accParams(extra));
  const post = (action, body = {}) => api(action, {}, { conn: state.acc?.conn, cid: state.acc?.id, ...body });

  // ---------- popover ----------
  let popEl = null;
  function closePop() { if (popEl) { popEl.remove(); popEl = null; } }
  function openPop(anchor, html, cls = '') {
    closePop();
    popEl = document.createElement('div');
    popEl.className = 'pop ' + cls;
    popEl.innerHTML = html;
    document.body.appendChild(popEl);
    const r = anchor.getBoundingClientRect();
    const w = popEl.offsetWidth;
    let left = r.left + window.scrollX;
    if (left + w > window.innerWidth - 12) left = Math.max(12, r.right + window.scrollX - w);
    popEl.style.left = left + 'px';
    popEl.style.top = (r.bottom + window.scrollY + 8) + 'px';
    setTimeout(() => document.addEventListener('click', outside), 0);
    function outside(e) { if (popEl && !popEl.contains(e.target)) { closePop(); document.removeEventListener('click', outside); } }
    return popEl;
  }

  // ---------- date range ----------
  const RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7', 'Last 7 days'], ['14', 'Last 14 days'],
                  ['30', 'Last 30 days'], ['this_month', 'This month'], ['last_month', 'Last month'], ['90', 'Last 90 days']];
  const ymd = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const ago = n => { const d = new Date(); d.setDate(d.getDate() - n); return d; };
  function setRange(key, from, to) {
    const t = new Date();
    let f, e = ago(1);
    if (key === 'today') { f = t; e = t; }
    else if (key === 'yesterday') f = ago(1);
    else if (key === 'this_month') { f = new Date(t.getFullYear(), t.getMonth(), 1); e = t; }
    else if (key === 'last_month') { f = new Date(t.getFullYear(), t.getMonth() - 1, 1); e = new Date(t.getFullYear(), t.getMonth(), 0); }
    else if (key === 'custom') { state.from = from; state.to = to; }
    else f = ago(+key);
    if (key !== 'custom') { state.from = ymd(f); state.to = ymd(e); }
    state.range = key;
    const fmt = s => new Date(s + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    $('#dateLabel').textContent = key === 'custom' ? `${fmt(state.from)} – ${fmt(state.to)}` : (RANGES.find(r => r[0] === key) || [0, ''])[1];
    try { localStorage.setItem('adhook_range', key); } catch {}
  }
  function datePicker(btn) {
    const p = openPop(btn, RANGES.map(([k, l]) => `<button class="opt ${state.range === k ? 'on' : ''}" data-r="${k}">${l}</button>`).join('') +
      `<div class="sep"></div><div class="custom-range"><div class="small muted">Custom range</div>
       <input type="date" id="cf" value="${state.from}"><input type="date" id="ct" value="${state.to}">
       <button class="btn sm primary" id="capply">Apply</button></div>`);
    p.style.minWidth = btn.offsetWidth + 'px';
    p.addEventListener('click', e => {
      const b = e.target.closest('[data-r]');
      if (b) { setRange(b.dataset.r); closePop(); reload(); }
      if (e.target.id === 'capply') {
        const f = $('#cf', p).value, t = $('#ct', p).value;
        if (!f || !t) return toast('Pick both dates');
        setRange('custom', f <= t ? f : t, f <= t ? t : f); closePop(); reload();
      }
    });
  }

  // ---------- accounts ----------
  const key = a => a.conn + ':' + a.id;
  const isActive = a => (a.status || 'ENABLED') === 'ENABLED';
  const STATUS = {
    CANCELED: ['Cancelled', 'This account was cancelled (Google cancels accounts with no spend for a long time). Open it in Google Ads and choose "Reactivate".'],
    SUSPENDED: ['Suspended', 'Google suspended this account (a policy or billing issue). Check notifications and billing in Google Ads.'],
    CLOSED: ['Closed', 'This account is closed; its data is not available via the API.'],
    NOT_ENABLED: ['Inactive', 'This account is not active yet: setup is incomplete (no billing/payment method) or it was cancelled.'],
  };

  async function loadAccounts(refresh = false) {
    const d = await api('accounts', refresh ? { refresh: 1 } : {});
    state.accounts = d.accounts;
    state.demo = d.demo;
    state.demoLabels = !!d.demo && d.demo_banner !== false; // config demo_banner=false hides every demo note
    const w = $('#warnBox');
    if (d.errors?.length) {
      w.innerHTML = '<b>Some accounts could not be loaded:</b><br>' + d.errors.map(esc).join('<br>') +
        '<br><span class="small">Refresh the account picker to retry, or <a href="connect.php">reconnect the Google account</a>.</span>';
      w.classList.remove('hidden');
    } else w.classList.add('hidden');
    const demo = $('#demoBox');
    if (state.demoLabels) {
      demo.innerHTML = `<b>Demo account:</b> Trivago campaign data. Edits show here only and nothing changes in Google Ads. For your live accounts, <a href="connect.php"><b>connect your Google account →</b></a>`;
      demo.classList.remove('hidden');
    } else demo.classList.add('hidden');

    let saved = null; try { saved = localStorage.getItem('adhook_acc_' + APP.user.username); } catch {}
    const active = state.accounts.filter(isActive);
    const pick = active.find(a => key(a) === saved) || active.find(a => state.acc && key(a) === key(state.acc)) || active[0] || state.accounts[0];
    setAccount(pick || null, false);
  }

  function setAccount(a, go = true) {
    state.acc = a;
    state.report = null;
    if (a) { try { localStorage.setItem('adhook_acc_' + APP.user.username, key(a)); } catch {} }
    accHeader(a);
    if (go) reload();
  }
  function accHeader(a) {
    $('#accTitle').textContent = a ? a.name : (state.accounts.length ? 'Select an account' : 'No accounts');
    $('#accSub').innerHTML = a ? `Customer ID: ${fmtId(a.id)} <span class="small">· ${esc(a.email)}${a.via ? ' · via ' + esc(a.via) : ''}${a.shared_by ? ` · <b>${a.access === 'view' ? 'View only' : 'Can edit'}</b> (shared by ${esc(a.shared_by)})` : ''}</span>` :
      (state.accounts.length ? '' : 'No Google Ads accounts found for this login. <a href="connect.php">Connect another account</a>');
  }

  function accountPicker(btn) {
    const render = q => {
      const list = state.accounts.filter(a => (a.name + a.id + a.email + (a.via || '')).toLowerCase().includes(q));
      let html = '', last = null;
      for (const a of list) {
        const grp = a.email + (a.shared_by ? ' · shared by ' + a.shared_by : '');
        if (grp !== last) { html += `<div class="acc-group">${icon(a.shared_by ? 'users' : 'globe', 'sm')}${esc(grp)}</div>`; last = grp; }
        const off = !isActive(a);
        html += `<button class="acc-item ${state.acc && key(a) === key(state.acc) ? 'on' : ''} ${off ? 'off' : ''}" data-k="${esc(key(a))}">
          <b>${esc(a.name)}${off ? `<span class="badge">${esc((STATUS[a.status] || [a.status])[0])}</span>` : ''}${a.access === 'view' ? '<span class="badge share">View only</span>' : a.access === 'edit' ? '<span class="badge share">Shared</span>' : ''}</b>
          <span>${fmtId(a.id)} · ${esc(a.currency)}${a.via ? ' · via ' + esc(a.via) : ''}</span></button>`;
      }
      return html || '<div class="muted" style="padding:12px">No accounts</div>';
    };
    const p = openPop(btn, `<div class="row" style="padding:4px"><input class="search" placeholder="Search account / email…" id="accQ" style="flex:1">
      <button class="btn icon sm" id="accRef" title="Refresh accounts">${icon('refresh', 'sm')}</button></div>
      <div class="list">${render('')}</div>
      <div class="sep"></div><a class="opt" href="connect.php">${icon('plus', 'sm')} Connect Google or manager account</a>`, 'acc-pop');
    $('#accQ', p).focus();
    $('#accQ', p).addEventListener('input', e => { $('.list', p).innerHTML = render(e.target.value.toLowerCase()); });
    $('#accRef', p).addEventListener('click', async () => {
      $('.list', p).innerHTML = '<div class="muted" style="padding:12px"><span class="spin"></span> Refreshing…</div>';
      try { await loadAccounts(true); closePop(); toast('Accounts refreshed'); reload(); } catch (e) { showError(e); closePop(); }
    });
    $('.list', p).addEventListener('click', e => {
      const b = e.target.closest('[data-k]');
      if (b) { closePop(); setAccount(state.accounts.find(a => key(a) === b.dataset.k)); }
    });
  }

  /** Account inactive ho to status card */
  function blockedHtml(status) {
    const [lbl, text] = STATUS[status] || [label(status), 'Data for this account is not available yet.'];
    if (state.acc) { state.acc.status = status; }
    return `<section class="card status-panel"><div class="sp">${icon('pause')}</div>
      <h2>Account ${esc(lbl)}</h2><p class="muted">${esc(text)}</p>
      <p class="muted small">Click the account name above to choose another account.</p></section>`;
  }

  // ---------- modal ----------
  function modal({ title, body, foot = '', wide = false, onOpen }) {
    const bg = document.createElement('div');
    bg.className = 'modal-bg';
    bg.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true">
      <div class="modal-head"><h2>${title}</h2><button class="btn icon ghost" data-close>${icon('x')}</button></div>
      <div class="modal-body">${body}</div>${foot ? `<div class="modal-foot">${foot}</div>` : ''}</div>`;
    document.body.appendChild(bg);
    const close = () => { bg.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    bg.addEventListener('click', e => { if (e.target === bg || e.target.closest('[data-close]')) close(); });
    const m = { el: bg, close, $: s => $(s, bg) };
    onOpen && onOpen(m);
    return m;
  }
  function confirmBox(text, okLabel = 'Confirm', danger = false) {
    return new Promise(res => {
      const m = modal({ title: 'Confirm', body: `<p style="margin-top:14px">${text}</p>${state.demoLabels ? '<p class="muted small">Demo mode: nothing changes in Google Ads.</p>' : ''}`,
        foot: `<button class="btn" data-close>Cancel</button><button class="btn ${danger ? 'danger' : 'primary'}" id="ok">${okLabel}</button>` });
      m.$('#ok').onclick = () => { m.close(); res(true); };
      m.el.addEventListener('click', e => { if (e.target.closest('[data-close]')) res(false); });
    });
  }
  /** Async action with a button busy state */
  async function busy(btn, fn) {
    const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spin"></span> ' + btn.textContent.trim();
    try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = old; }
  }

  // ---------- sortable table ----------
  /**
   * cols: [{k, label, cls:'l', html:(row)=>string, sort:true}]
   * opts: {rows, sortKey, dir, empty, footer:(rows)=>string, rowAttr:(row)=>string, filter:(row)=>bool}
   */
  class Table {
    constructor(el, cols, opts = {}) {
      this.el = el; this.cols = cols; this.o = Object.assign({ sortKey: null, dir: 'desc', empty: 'No data', rows: [] }, opts);
      el.addEventListener('click', e => {
        const th = e.target.closest('th[data-k]');
        if (!th) return;
        const k = th.dataset.k;
        this.o.dir = this.o.sortKey === k && this.o.dir === 'desc' ? 'asc' : 'desc';
        this.o.sortKey = k; this.render();
      });
    }
    set rows(r) { this.o.rows = r; this.render(); }
    get visible() {
      let r = this.o.filter ? this.o.rows.filter(this.o.filter) : [...this.o.rows];
      const k = this.o.sortKey, d = this.o.dir === 'asc' ? 1 : -1;
      if (k) r.sort((a, b) => (typeof a[k] === 'string' || typeof b[k] === 'string' ? String(a[k] ?? '').localeCompare(String(b[k] ?? '')) : (+a[k] || 0) - (+b[k] || 0)) * d);
      return r;
    }
    render() {
      const rows = this.visible;
      const th = this.cols.map(c => `<th class="${c.cls || ''} ${c.sort === false ? 'nosort' : ''} ${this.o.sortKey === c.k ? 'sorted ' + this.o.dir : ''}" ${c.sort === false ? '' : `data-k="${c.k}"`}>${c.label}</th>`).join('');
      const body = rows.length ? rows.map(r => `<tr ${this.o.rowAttr ? this.o.rowAttr(r) : ''}>${this.cols.map(c => `<td class="${c.cls || ''}">${c.html ? c.html(r) : esc(r[c.k])}</td>`).join('')}</tr>`).join('')
        : `<tr><td class="empty" colspan="${this.cols.length}">${this.o.empty}</td></tr>`;
      this.el.innerHTML = `<table class="data"><thead><tr>${th}</tr></thead><tbody>${body}</tbody>${rows.length && this.o.footer ? `<tfoot>${this.o.footer(rows)}</tfoot>` : ''}</table>`;
      this.o.after && this.o.after(rows);
    }
  }
  /** Standard metric columns */
  const metricCols = (extra = []) => [
    ...extra,
    { k: 'impr', label: 'Impr.', html: r => num(r.impr) },
    { k: 'clicks', label: 'Clicks', html: r => num(r.clicks) },
    { k: 'ctr', label: 'CTR', html: r => dec(r.ctr) + '%' },
    { k: 'cpc', label: 'Avg CPC', html: r => money(r.cpc, true) },
    { k: 'cost', label: 'Cost', html: r => `<b>${money(r.cost)}</b>` },
    { k: 'conv', label: 'Conv.', html: r => dec(r.conv) },
    { k: 'cpa', label: 'CPA', html: r => r.conv ? money(r.cpa) : '—' },
    { k: 'value', label: 'Conv. value', html: r => money(r.value) },
    { k: 'roas', label: 'ROAS', html: r => roasCell(r) },
  ];
  const totalsRow = (rows, lead = 1, trail = 0) => {
    const t = sum(rows);
    return `<tr><td class="l">Total (${rows.length})</td>${'<td></td>'.repeat(lead - 1)}<td>${num(t.impr)}</td><td>${num(t.clicks)}</td><td>${dec(t.ctr)}%</td><td>${money(t.cpc, true)}</td><td>${money(t.cost)}</td><td>${dec(t.conv)}</td><td>${t.conv ? money(t.cpa) : '—'}</td><td>${money(t.value)}</td><td>${roasCell(t)}</td>${'<td></td>'.repeat(trail)}</tr>`;
  };

  function csvDownload(name, head, rows) {
    const csv = [head, ...rows].map(r => r.map(v => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' }));
    a.download = name; a.click(); URL.revokeObjectURL(a.href);
  }

  // ---------- sparkline (inline SVG) ----------
  function spark(values, color) {
    if (!values.length) return '';
    const w = 160, h = 44, max = Math.max(...values, 1), min = Math.min(...values, 0);
    const pts = values.map((v, i) => [values.length === 1 ? w : i / (values.length - 1) * w, h - 3 - (v - min) / (max - min || 1) * (h - 8)]);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    const id = 'g' + Math.random().toString(36).slice(2, 8);
    return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">
      <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${color}" stop-opacity=".28"/><stop offset="1" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>
      <path d="${d} L${w} ${h} L0 ${h} Z" fill="url(#${id})"/><path d="${d}" fill="none" stroke="${color}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/></svg>`;
  }

  // ---------- router ----------
  const views = {};
  function route() {
    const [name, ...rest] = (location.hash.slice(1) || 'dashboard').split('/');
    const HIDDEN = ['new-accounts', 'link-accounts', 'access', 'affreport']; // pages removed from this build
    const v = views[name] && !HIDDEN.includes(name) ? name : 'dashboard';
    state.view = v;
    $$('#nav a').forEach(a => a.classList.toggle('on', a.dataset.v === (v === 'campaign' || v === 'new-campaign' ? 'campaigns' : (v === 'new-accounts' || v === 'link-accounts') ? 'overview' : v)));
    const V = views[v];
    closePop();
    $$('.modal-bg').forEach(m => m.remove()); // page badla to khule popups band
    $('#eyebrow').textContent = V.eyebrow || 'Accounts';
    $('#dateControls').style.display = V.dates === false ? 'none' : '';
    $('#csvBtn').style.display = V.csv ? '' : 'none';
    $('#copyId').style.display = V.needsAccount === false ? 'none' : '';
    $('#sidebar').classList.remove('open');
    showError(null);
    const el = $('#view');
    if (V.needsAccount !== false) accHeader(state.acc); // show the account in the header again
    if (V.needsAccount !== false && !state.acc) { el.innerHTML = '<section class="card status-panel"><h2>No account selected</h2><p class="muted">Choose an account above or connect a Google account.</p></section>'; return; }
    if (V.needsAccount !== false && !isActive(state.acc)) { el.innerHTML = blockedHtml(state.acc.status); return; }
    el.innerHTML = '';
    V.render(el, rest);
  }
  const reload = () => route();

  /** Apply a white-label brand live (colour accents + logo/name badge + title). */
  function applyBrand(brand) {
    if (!brand) return;
    const c = brand.color || '#2563eb';
    const tint = (hex, p) => { hex = hex.replace('#', ''); const r = parseInt(hex.slice(0, 2), 16), g = parseInt(hex.slice(2, 4), 16), bl = parseInt(hex.slice(4, 6), 16); const m = x => Math.round(x + (255 - x) * p); const h2 = x => x.toString(16).padStart(2, '0'); return '#' + h2(m(r)) + h2(m(g)) + h2(m(bl)); };
    const rs = document.documentElement.style;
    rs.setProperty('--accent', c);
    rs.setProperty('--accent-2', tint(c, 0.82));
    rs.setProperty('--accent-3', tint(c, 0.93));
    const logo = brand.logo_url
      ? `<span class="logo"><img src="${esc(brand.logo_url)}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit"></span>`
      : `<span class="logo">${esc(brand.logo || 'A')}</span>`;
    const parts = String(brand.name || 'TrakrHub').split(' ');
    const nameHtml = `${logo} ${esc(parts[0])}${parts.length > 1 ? ' <span class="b2">' + esc(parts.slice(1).join(' ')) + '</span>' : ''}`;
    document.querySelectorAll('.sidebar .brand').forEach(el => el.innerHTML = nameHtml);
    if (brand.name) document.title = brand.name;
    window.APP.brand = brand;
  }

  return { $, $$, esc, icon, icons, state, num, dec, money, fmtId, label, derive, sum, statusPill, roasCell,
    toast, showError, api, get, post, openPop, closePop, setRange, datePicker, loadAccounts, setAccount, accountPicker,
    blockedHtml, modal, confirmBox, busy, Table, metricCols, totalsRow, csvDownload, spark, views, route, reload, key, isActive, applyBrand };
})();
