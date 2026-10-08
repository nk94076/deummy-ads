/* New Search campaign wizard + RSA ad editor + Locations/Languages (targeting) */
(() => {
  const { $, $$, esc, icon, state, money, label } = A;
  const cur = () => state.report?.currency || state.acc?.currency || '';
  const DRAFT_KEY = () => 'adhook_newcamp_' + (state.acc?.id || '');
  const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} },
    del(k) { try { localStorage.removeItem(k); } catch {} },
  };

  // Languages + common locations: loaded once per account
  let langCache = null;
  async function langs() {
    if (langCache && langCache.acc === state.acc?.id) return langCache;
    const r = await A.get('languages');
    langCache = { acc: state.acc?.id, languages: r.languages, geos: r.common_geos };
    return langCache;
  }

  // ================= RSA editor =================
  /** host me RSA form + live preview (mobile/desktop). return { get(), set(v), host } */
  function rsaEditor(host, init = {}) {
    host.innerHTML = `<div class="rsa">
      <div class="rsa-form">
        <label class="f">Final URL <span class="muted">(landing page)</span></label>
        <input class="rsa-url" data-field="final_url" value="${esc(init.final_url || '')}" placeholder="https://www.lycamobile.us/en/">
        <div class="grid2"><div><label class="f">Display path 1 <span class="muted">(optional)</span></label><input class="rsa-p1" maxlength="15" value="${esc(init.path1 || '')}" placeholder="plans"></div>
        <div><label class="f">Display path 2 <span class="muted">(optional)</span></label><input class="rsa-p2" maxlength="15" value="${esc(init.path2 || '')}" placeholder="unlimited"></div></div>
        <label class="f" data-field="headlines">Headlines <span class="muted">(3–15, max 30 characters)</span> <span class="count-pill rsa-hc"></span></label>
        <div class="rsa-h"></div>
        <button type="button" class="btn sm rsa-addh">${icon('plus', 'sm')} Add headline</button>
        <label class="f" data-field="descriptions">Descriptions <span class="muted">(2–4, max 90 characters)</span> <span class="count-pill rsa-dc"></span></label>
        <div class="rsa-d"></div>
        <button type="button" class="btn sm rsa-addd">${icon('plus', 'sm')} Add description</button>
      </div>
      <div class="rsa-side">
        <div class="prev-head"><b>${icon('ad', 'sm')} Ad preview</b>
          <div class="seg-mini"><button type="button" data-pv="mobile" class="on">${icon('phone', 'sm')} Mobile</button><button type="button" data-pv="desktop">${icon('monitor', 'sm')} Desktop</button></div></div>
        <div class="ad-prev mobile"></div>
        <div class="rsa-strength"></div>
        <div class="hint">Preview is for reference only. Google shows headlines in varying order.</div></div>
    </div>`;
    const row = (v, max, cls) => `<div class="cnt-row"><input class="${cls}" value="${esc(v)}" maxlength="${max + 20}"><span class="cnt"></span><button type="button" class="btn icon sm ghost" data-rm title="Remove">${icon('x', 'sm')}</button></div>`;
    const H = $('.rsa-h', host), D = $('.rsa-d', host);
    const fill = v => {
      const h = v.headlines?.length ? [...v.headlines] : ['', '', ''];
      const d = v.descriptions?.length ? [...v.descriptions] : ['', ''];
      while (h.length < 3) h.push('');
      while (d.length < 2) d.push('');
      H.innerHTML = h.slice(0, 15).map(x => row(x, 30, 'hl')).join('');
      D.innerHTML = d.slice(0, 4).map(x => row(x, 90, 'ds')).join('');
    };
    fill(init);
    const vals = sel => $$(sel, host).map(i => i.value.trim()).filter(Boolean);
    function refresh() {
      $$('.cnt-row', host).forEach(r => {
        const i = r.querySelector('input'), max = i.classList.contains('hl') ? 30 : 90, n = [...i.value.trim()].length;
        const c = r.querySelector('.cnt'); c.textContent = `${n}/${max}`; c.classList.toggle('over', n > max);
        i.classList.toggle('bad-in', n > max);
      });
      const hs = vals('.hl'), ds = vals('.ds');
      const hc = $('.rsa-hc', host), dc = $('.rsa-dc', host);
      hc.textContent = hs.length + '/15'; hc.classList.toggle('bad', hs.length < 3);
      dc.textContent = ds.length + '/4'; dc.classList.toggle('bad', ds.length < 2);
      $('.rsa-addh', host).disabled = $$('.hl', host).length >= 15;
      $('.rsa-addd', host).disabled = $$('.ds', host).length >= 4;
      let dom = '';
      try { dom = new URL($('.rsa-url', host).value.trim()).hostname.replace(/^www\./, ''); } catch { dom = 'example.com'; }
      const path = [$('.rsa-p1', host).value.trim(), $('.rsa-p2', host).value.trim()].filter(Boolean);
      $('.ad-prev', host).innerHTML = `<div class="ap-top"><span class="ap-fav">${esc(dom[0] || 'e').toUpperCase()}</span><div><div class="ap-site">${esc(dom.split('.')[0])}</div>
          <div class="ap-url">${esc(dom)}${path.length ? ' › ' + path.map(esc).join(' › ') : ''}</div></div></div>
        <div class="ap-spons">Sponsored</div>
        <div class="ap-h">${esc(hs.slice(0, 3).join(' | ') || 'Headline 1 | Headline 2 | Headline 3')}</div>
        <div class="ap-d">${esc(ds.slice(0, 2).join(' ') || 'Description shows here.')}</div>`;
    }
    host.addEventListener('input', refresh);
    host.addEventListener('click', e => {
      const pv = e.target.closest('[data-pv]');
      if (pv) { $$('[data-pv]', host).forEach(b => b.classList.toggle('on', b === pv)); $('.ad-prev', host).className = 'ad-prev ' + pv.dataset.pv; return; }
      if (e.target.closest('.rsa-addh')) { H.insertAdjacentHTML('beforeend', row('', 30, 'hl')); H.lastElementChild.querySelector('input').focus(); }
      if (e.target.closest('.rsa-addd')) { D.insertAdjacentHTML('beforeend', row('', 90, 'ds')); D.lastElementChild.querySelector('input').focus(); }
      const rm = e.target.closest('[data-rm]');
      if (rm) {
        const r = rm.closest('.cnt-row'), list = r.parentElement, min = list === H ? 3 : 2;
        if (list.children.length > min) r.remove(); else r.querySelector('input').value = '';
      }
      refresh();
      host.dispatchEvent(new Event('input', { bubbles: true }));
    });
    refresh();
    return {
      host,
      get: () => ({ final_url: $('.rsa-url', host).value.trim(), path1: $('.rsa-p1', host).value.trim(), path2: $('.rsa-p2', host).value.trim(),
                    headlines: vals('.hl'), descriptions: vals('.ds') }),
      set(v) {
        if (v.final_url !== undefined) $('.rsa-url', host).value = v.final_url;
        if (v.path1 !== undefined) $('.rsa-p1', host).value = v.path1;
        if (v.path2 !== undefined) $('.rsa-p2', host).value = v.path2;
        if (v.headlines || v.descriptions) fill({ headlines: v.headlines || vals('.hl'), descriptions: v.descriptions || vals('.ds') });
        refresh();
      },
    };
  }

  // ================= Location picker =================
  const gname = n => String(n || '').replace(/,(?=\S)/g, ', ');
  function geoPicker(host, selected = [], common = [], { neg = false, empty = 'No locations selected' } = {}) {
    const sel = new Map(selected.map(g => [String(g.id), g]));
    host.innerHTML = `<div class="geo${neg ? ' neg' : ''}">
      <div class="chips-sel"></div>
      <div class="geo-quick">${common.map(g => `<button type="button" class="chip add" data-g="${g.id}">+ ${esc(g.name)}</button>`).join('')}</div>
      <div class="geo-search"><div class="search-in">${icon('search', 'sm')}<input class="geo-q" placeholder="Search city / state / country… (e.g. Texas, Delhi)"></div>
        <div class="geo-res hidden"></div></div></div>`;
    const draw = () => {
      $('.chips-sel', host).innerHTML = sel.size
        ? [...sel.values()].map(g => `<span class="chip on">${esc(gname(g.name))}${g.type && g.type !== 'Country' ? ` <span class="muted">· ${esc(g.type)}</span>` : ''}<button type="button" data-x="${g.id}" title="Remove">×</button></span>`).join('')
        : `<span class="muted small">${empty}</span>`;
      $$('.geo-quick [data-g]', host).forEach(b => b.classList.toggle('hidden', sel.has(b.dataset.g)));
      host.dispatchEvent(new Event('input', { bubbles: true }));
    };
    let t, lastQ = '';
    const res = $('.geo-res', host);
    $('.geo-q', host).addEventListener('input', e => {
      clearTimeout(t);
      const q = e.target.value.trim();
      if (q.length < 2) { res.classList.add('hidden'); return; }
      t = setTimeout(async () => {
        lastQ = q;
        res.classList.remove('hidden'); res.innerHTML = '<div class="gr muted"><span class="spin"></span> Searching…</div>';
        try {
          const r = await A.get('geo_search', { q });
          if (lastQ !== q) return;
          res.innerHTML = r.results.length ? r.results.map(g => `<button type="button" class="gr" data-add='${esc(JSON.stringify(g))}'><b>${esc(gname(g.name))}</b> <span class="muted">${esc(g.type)}${g.country ? ' · ' + esc(g.country) : ''}</span>${sel.has(g.id) ? ' ✓' : ''}</button>`).join('')
            : '<div class="gr muted">No results. Try the English spelling.</div>';
        } catch (err) { res.innerHTML = `<div class="gr muted">${esc(err.error || 'Error')}</div>`; }
      }, 350);
    });
    host.addEventListener('click', e => {
      const q = e.target.closest('[data-g]');
      if (q) { const g = common.find(x => x.id === q.dataset.g); sel.set(g.id, g); draw(); }
      const a = e.target.closest('[data-add]');
      if (a) { const g = JSON.parse(a.dataset.add); sel.set(String(g.id), g); res.classList.add('hidden'); $('.geo-q', host).value = ''; draw(); }
      const x = e.target.closest('[data-x]');
      if (x) { sel.delete(x.dataset.x); draw(); }
    });
    host.addEventListener('focusout', e => { if (!host.contains(e.relatedTarget)) setTimeout(() => res.classList.add('hidden'), 150); });
    draw();
    return { get: () => [...sel.values()], set(list) { sel.clear(); list.forEach(g => sel.set(String(g.id), g)); draw(); } };
  }

  // ================= Language picker =================
  function langPicker(host, all, selected = []) {
    const sel = new Set(selected.map(String));
    const byId = Object.fromEntries(all.map(l => [l.id, l]));
    host.innerHTML = `<div class="chips-sel"></div>
      <select class="lang-add" style="max-width:280px;margin-top:8px"><option value="">+ Add language…</option>${all.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select>`;
    const draw = () => {
      $('.chips-sel', host).innerHTML = sel.size ? [...sel].map(id => `<span class="chip on">${esc(byId[id]?.name || 'Language ' + id)}<button type="button" data-x="${id}">×</button></span>`).join('')
        : '<span class="muted small">No languages (all languages)</span>';
      host.dispatchEvent(new Event('input', { bubbles: true }));
    };
    $('.lang-add', host).onchange = e => { if (e.target.value) sel.add(e.target.value); e.target.value = ''; draw(); };
    host.addEventListener('click', e => { const x = e.target.closest('[data-x]'); if (x) { sel.delete(x.dataset.x); draw(); } });
    draw();
    return { get: () => [...sel], set(list) { sel.clear(); list.forEach(x => sel.add(String(x))); draw(); } };
  }

  // ================= Ad schedule =================
  const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
  const DSH = { MONDAY: 'Mon', TUESDAY: 'Tue', WEDNESDAY: 'Wed', THURSDAY: 'Thu', FRIDAY: 'Fri', SATURDAY: 'Sat', SUNDAY: 'Sun' };
  const TIMES = Array.from({ length: 97 }, (_, i) => `${String(Math.floor(i / 4)).padStart(2, '0')}:${String(i % 4 * 15).padStart(2, '0')}`);
  const t12 = t => { const [h, m] = t.split(':').map(Number); if (h === 24) return '12:00 AM (midnight)'; return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`; };
  const mins = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  /** Compress per-day rows: same time on all 7 days = "Every day", Mon-Fri = "Mon–Fri" */
  function compress(rows) {
    const g = new Map();
    const expand = d => d === 'ALL' ? DAYS : d === 'WEEKDAYS' ? DAYS.slice(0, 5) : d === 'WEEKEND' ? DAYS.slice(5) : [d];
    rows.forEach(r => { const k = r.start + '|' + r.end; if (!g.has(k)) g.set(k, new Set()); expand(r.day).forEach(d => g.get(k).add(d)); });
    const out = [];
    for (const [k, ds] of g) {
      const [start, end] = k.split('|');
      const has = list => list.every(d => ds.has(d));
      if (has(DAYS)) { out.push({ day: 'ALL', start, end }); continue; }
      if (has(DAYS.slice(0, 5))) { out.push({ day: 'WEEKDAYS', start, end }); DAYS.slice(0, 5).forEach(d => ds.delete(d)); }
      if (has(DAYS.slice(5))) { out.push({ day: 'WEEKEND', start, end }); DAYS.slice(5).forEach(d => ds.delete(d)); }
      DAYS.filter(d => ds.has(d)).forEach(d => out.push({ day: d, start, end }));
    }
    return out;
  }
  function scheduleEditor(host, rows = []) {
    host.innerHTML = `<div class="sched">
      <div class="row" style="gap:6px;flex-wrap:wrap;margin-bottom:10px">
        <button type="button" class="chip add" data-pre="none">24x7 (no schedule)</button>
        <button type="button" class="chip add" data-pre="office">Mon–Fri 9 AM–6 PM</button>
        <button type="button" class="chip add" data-pre="day">Every day 8 AM–11 PM</button>
        <button type="button" class="chip add" data-pre="night">Every day 6 PM–12 AM</button></div>
      <div class="sched-rows"></div>
      <button type="button" class="btn sm" data-addrow>${icon('plus', 'sm')} Add time slot</button>
      <div class="sched-vis"></div>
      <div class="hint">Times are in the account's time zone${state.acc?.time_zone ? ' (' + esc(state.acc.time_zone) + ')' : ''}. No slots = ads run 24x7.</div></div>`;
    const R = $('.sched-rows', host);
    const opt = (sel, list) => list.map(t => `<option value="${t}" ${t === sel ? 'selected' : ''}>${t12(t)}</option>`).join('');
    const row = r => `<div class="sched-row">
      <select class="s-day">${[['ALL', 'Every day'], ['WEEKDAYS', 'Mon–Fri'], ['WEEKEND', 'Sat–Sun'], ...DAYS.map(d => [d, label(d)])].map(([v, l]) => `<option value="${v}" ${v === r.day ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <select class="s-st">${opt(r.start, TIMES.slice(0, 96))}</select><span class="muted">to</span>
      <select class="s-en">${opt(r.end, TIMES.slice(1))}</select>
      <button type="button" class="btn icon sm ghost" data-rm title="Remove">${icon('x', 'sm')}</button></div>`;
    const set = list => { R.innerHTML = list.map(row).join(''); draw(); };
    const get = () => $$('.sched-row', host).map(r => ({ day: r.querySelector('.s-day').value, start: r.querySelector('.s-st').value, end: r.querySelector('.s-en').value }));
    function draw() {
      const cov = Object.fromEntries(DAYS.map(d => [d, []]));
      let bad = false;
      get().forEach(r => {
        const ds = r.day === 'ALL' ? DAYS : r.day === 'WEEKDAYS' ? DAYS.slice(0, 5) : r.day === 'WEEKEND' ? DAYS.slice(5) : [r.day];
        if (mins(r.end) <= mins(r.start)) bad = true;
        ds.forEach(d => cov[d].push([mins(r.start), mins(r.end)]));
      });
      const any = get().length;
      $('.sched-vis', host).innerHTML = `<div class="sv-grid">${DAYS.map(d => `<div class="sv-d">${DSH[d]}</div><div class="sv-bar">${any ? cov[d].map(([a, b]) => b > a ? `<i style="left:${a / 14.4}%;width:${(b - a) / 14.4}%"></i>` : '').join('') : '<i style="left:0;width:100%"></i>'}</div>`).join('')}
        <div></div><div class="sv-ax"><span>12AM</span><span>6AM</span><span>12PM</span><span>6PM</span><span>12AM</span></div></div>
        ${bad ? '<div class="hint" style="color:var(--bad)">End time must be after start time.</div>' : ''}`;
      host.dispatchEvent(new Event('input', { bubbles: true }));
    }
    host.addEventListener('change', draw);
    host.addEventListener('click', e => {
      const p = e.target.closest('[data-pre]');
      if (p) set({ none: [], office: [{ day: 'WEEKDAYS', start: '09:00', end: '18:00' }], day: [{ day: 'ALL', start: '08:00', end: '23:00' }], night: [{ day: 'ALL', start: '18:00', end: '24:00' }] }[p.dataset.pre]);
      if (e.target.closest('[data-addrow]')) { R.insertAdjacentHTML('beforeend', row({ day: 'ALL', start: '09:00', end: '18:00' })); draw(); }
      const rm = e.target.closest('[data-rm]'); if (rm) { rm.closest('.sched-row').remove(); draw(); }
    });
    set(compress(rows));
    return { get };
  }

  // ================= Keyword preview =================
  function parseKw(text, def) {
    const out = new Map();
    text.split('\n').forEach(l => {
      l = l.trim(); if (!l) return;
      let m = def, t = l;
      if (/^\[.+\]$/.test(l)) { m = 'EXACT'; t = l.slice(1, -1); } else if (/^".+"$/.test(l)) { m = 'PHRASE'; t = l.slice(1, -1); }
      t = t.replace(/[+\[\]"]/g, '').replace(/\s+/g, ' ').trim();
      if (t) out.set(t.toLowerCase() + '|' + m, { t, m });
    });
    return [...out.values()];
  }

  // ================= Health score + live validation =================
  const STEPS = [
    ['s1', 'Campaign', 'Budget & bidding', 'wallet'],
    ['s2', 'Locations', 'Language & schedule', 'pin'],
    ['s3', 'Ad group', 'Keywords', 'tag'],
    ['s4', 'Responsive ad', 'Headlines & descriptions', 'ad'],
    ['s5', 'Tracking', 'URL & conversions', 'target'],
  ];
  const validUrl = u => { try { const x = new URL(u); return /^https?:$/.test(x.protocol) && x.hostname.includes('.'); } catch { return false; } };
  const mins2 = t => { const [h, m] = String(t).split(':').map(Number); return h * 60 + m; };

  /** d = form data, ctx = {conv: {count}|null}. return {parts, overall, issues, adStrength} */
  function health(d, ctx) {
    const issues = [];
    const I = (lvl, step, text) => issues.push({ lvl, step, text });
    const kws = parseKw(d.keywords, d.match);
    const negs = parseKw(d.negatives, 'PHRASE');
    const hs = d.ad.headlines, ds = d.ad.descriptions;

    // ---- Setup ----
    if (!d.name) I('err', 's1', 'Enter a campaign name');
    if (!(+d.budget > 0)) I('err', 's1', 'Enter a daily budget');
    if (d.bidding === 'MANUAL_CPC' && !(+d.cpc_bid > 0)) I('err', 's1', 'Manual CPC needs a default max CPC bid');
    if (d.bidding === 'MANUAL_CPC' && +d.cpc_bid > 0 && +d.budget > 0 && +d.budget < +d.cpc_bid * 10) I('warn', 's1', `Low budget: only ~${Math.floor(d.budget / d.cpc_bid)} clicks/day`);
    if (d.bidding === 'MAXIMIZE_CLICKS' && +d.max_cpc_limit > 0 && +d.budget > 0 && +d.max_cpc_limit > +d.budget) I('warn', 's1', 'Max CPC limit is higher than the daily budget');
    if (d.bidding === 'MAXIMIZE_CONVERSIONS' && ctx.conv && ctx.conv.count === 0) I('err', 's1', 'Maximize conversions needs conversion tracking (no active conversion action in this account)');
    // ---- Targeting ----
    const locIds = d.locations.map(g => String(g.id)), exIds = d.excluded.map(g => String(g.id));
    if (!locIds.length) I('err', 's2', 'Select at least one location');
    const clash = d.locations.filter(g => exIds.includes(String(g.id)));
    if (clash.length) I('err', 's2', `Location conflict: ${clash.map(g => gname(g.name)).join(', ')} is both targeted and excluded`);
    if (!d.languages.length) I('warn', 's2', 'No language selected (ads show in all languages)');
    if (d.geo_type === 'PRESENCE_OR_INTEREST') I('warn', 's2', '"Presence or interest" can reach people outside your locations. Presence is recommended.');
    const perDay = {};
    for (const r of d.schedule) {
      if (mins2(r.end) <= mins2(r.start)) { I('err', 's2', 'Ad schedule: end time is before start time'); break; }
      const days = r.day === 'ALL' ? DAYS : r.day === 'WEEKDAYS' ? DAYS.slice(0, 5) : r.day === 'WEEKEND' ? DAYS.slice(5) : [r.day];
      for (const dd of days) {
        const a = mins2(r.start), b = mins2(r.end);
        if ((perDay[dd] || []).some(([x, y]) => a < y && x < b)) { I('err', 's2', `Ad schedule overlap (${DSH[dd]})`); break; }
        (perDay[dd] = perDay[dd] || []).push([a, b]);
      }
    }
    // ---- Keywords ----
    const rawLines = d.keywords.split('\n').map(x => x.trim()).filter(Boolean).length;
    if (!kws.length) I('err', 's3', 'Add at least one keyword');
    else if (kws.length < 3) I('warn', 's3', 'Very few keywords (3–20 is good)');
    if (kws.length > 20) I('warn', 's3', `${kws.length} keywords in one ad group. Above 20, split into theme-based ad groups.`);
    if (rawLines > kws.length) I('warn', 's3', `${rawLines - kws.length} duplicate keyword(s) will be removed automatically`);
    const long = kws.filter(k => k.t.length > 80 || k.t.split(' ').length > 10);
    if (long.length) I('err', 's3', `Keyword too long: "${long[0].t.slice(0, 40)}…" (max 80 chars / 10 words)`);
    const oneBroad = kws.filter(k => k.m === 'BROAD' && !k.t.includes(' '));
    if (oneBroad.length) I('warn', 's3', `Single-word broad keywords (${oneBroad.slice(0, 3).map(k => k.t).join(', ')}) bring a lot of irrelevant traffic`);
    if (!negs.length) I('warn', 's3', 'No negative keywords (e.g. free, jobs) — you may get irrelevant clicks');
    const kwSet = new Set(kws.map(k => k.t.toLowerCase()));
    const badNeg = negs.filter(n => [...kwSet].some(k => (' ' + k + ' ').includes(' ' + n.t.toLowerCase() + ' ')));
    if (badNeg.length) I('err', 's3', `Negative "${badNeg[0].t}" would block one of your own keywords`);
    // ---- Ad ----
    if (!validUrl(d.ad.final_url)) I('err', 's4', 'Invalid final URL (enter a full URL with https://)');
    else if (!/^https:/i.test(d.ad.final_url)) I('warn', 's4', 'Final URL is not https');
    if (hs.length < 3) I('err', 's4', `Too few headlines (${hs.length}/3 minimum)`);
    else if (hs.length < 8) I('warn', 's4', `Weak ad: only ${hs.length} headlines. Add 8–15 for good Ad strength.`);
    if (ds.length < 2) I('err', 's4', `Too few descriptions (${ds.length}/2 minimum)`);
    else if (ds.length < 4) I('warn', 's4', `${ds.length} descriptions. 4 do to ad strength badhegi`);
    if (hs.some(h => [...h].length > 30)) I('err', 's4', 'A headline is longer than 30 characters');
    if (ds.some(x => [...x].length > 90)) I('err', 's4', 'A description is longer than 90 characters');
    const dupH = hs.filter((h, i) => hs.findIndex(x => x.toLowerCase() === h.toLowerCase()) !== i);
    if (dupH.length) I('err', 's4', `Duplicate headline: "${dupH[0]}"`);
    if (hs.some(h => h.includes('!'))) I('err', 's4', '"!" is not allowed in headlines (Google policy)');
    if (hs.concat(ds).some(t => /\b[A-Z]{5,}\b/.test(t) && !/\b(USA|FAQ)\b/.test(t))) I('warn', 's4', 'ALL CAPS words may be rejected by policy');
    const topKw = kws.slice(0, 10).map(k => k.t.toLowerCase());
    const kwInHead = hs.filter(h => topKw.some(k => h.toLowerCase().includes(k) || k.split(' ').filter(w => w.length > 3).every(w => h.toLowerCase().includes(w)))).length;
    if (kws.length && hs.length >= 3 && kwInHead < 2) I('warn', 's4', 'Use your keywords in the headlines (in at least 2 headlines)');
    if (hs.length >= 3 && new Set(hs.map(h => h.toLowerCase().split(' ')[0])).size < hs.length / 2) I('warn', 's4', 'Headlines are too similar; try different angles (offer, price, benefit, CTA)');
    // ---- Tracking ----
    const sfx = d.final_url_suffix;
    if (sfx && /\s/.test(sfx)) I('err', 's5', 'The final URL suffix must not contain spaces');
    if (sfx && !sfx.includes('=')) I('warn', 's5', 'The suffix does not look like key=value');
    const tpl = d.tracking_url_template;
    if (tpl && !/^(https?:\/\/|\{lpurl)/i.test(tpl)) I('err', 's5', 'Tracking template must start with {lpurl} or https://');
    if (tpl && !/\{(lpurl|unescapedlpurl|escapedlpurl)/i.test(tpl)) I('warn', 's5', 'Tracking template has no {lpurl}; it will not redirect to the landing page');
    if (ctx.conv && ctx.conv.count === 0) I('warn', 's5', 'Missing conversion tracking: set up a conversion action in the Conversions page');

    const score = step => {
      const e = issues.filter(x => x.step === step && x.lvl === 'err').length, w = issues.filter(x => x.step === step && x.lvl === 'warn').length;
      return Math.max(0, Math.min(100, 100 - e * 35 - w * 12));
    };
    // Ad strength: similar to Google (headlines + descriptions + keywords + uniqueness)
    let ad = Math.min(hs.length, 10) * 5 + Math.min(ds.length, 4) * 7.5 + (kwInHead >= 2 ? 10 : kwInHead * 5) + (d.ad.path1 ? 5 : 0) + (validUrl(d.ad.final_url) ? 5 : 0);
    ad = Math.max(0, Math.min(100, Math.round(ad - issues.filter(x => x.step === 's4' && x.lvl === 'err').length * 25)));
    let kwScore = !kws.length ? 0 : kws.length < 3 ? 60 : 100;
    kwScore = Math.max(0, kwScore - issues.filter(x => x.step === 's3' && x.lvl === 'err').length * 35 - issues.filter(x => x.step === 's3' && x.lvl === 'warn').length * 10);
    let track = 100 - (ctx.conv && ctx.conv.count === 0 ? 30 : 0) - (!ctx.conv ? 10 : 0) - issues.filter(x => x.step === 's5' && x.lvl === 'err').length * 35 - issues.filter(x => x.step === 's5' && x.lvl === 'warn' && !x.text.startsWith('Missing')).length * 10;
    track = Math.max(0, track);
    const parts = { setup: score('s1'), targeting: score('s2'), keywords: kwScore, ad, tracking: track };
    const overall = Math.round(parts.setup * .15 + parts.targeting * .2 + parts.keywords * .25 + parts.ad * .3 + parts.tracking * .1);
    const adLabel = ad >= 80 ? 'Excellent' : ad >= 60 ? 'Good' : ad >= 40 ? 'Average' : 'Poor';
    issues.sort((a, b) => (a.lvl === 'err' ? 0 : 1) - (b.lvl === 'err' ? 0 : 1));
    return { parts, overall, issues, adLabel, kws, negs };
  }
  const tone = v => v >= 80 ? 'good' : v >= 55 ? 'mid' : 'low';
  const ring = (v, size = 92) => {
    const r = size / 2 - 7, c = 2 * Math.PI * r;
    return `<svg class="ring ${tone(v)}" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" aria-label="${v}%"><circle cx="${size / 2}" cy="${size / 2}" r="${r}" class="rb"/>
      <circle cx="${size / 2}" cy="${size / 2}" r="${r}" class="rf" stroke-dasharray="${c * v / 100} ${c}" transform="rotate(-90 ${size / 2} ${size / 2})"/>
      <text x="50%" y="50%" dy=".35em" text-anchor="middle">${v}%</text></svg>`;
  };

  // ================= Smart Campaign Builder panel =================
  function smartPanel(host, apply) {
    host.innerHTML = `<div class="sb-head"><div class="sb-ic">${icon('spark')}</div><div><h3>Smart Campaign Builder</h3>
        <p class="muted small">Enter a website URL. The tool reads the page and suggests business, keywords, headlines, location, bidding and budget.</p></div></div>
      <div class="sb-in"><div class="search-in">${icon('globe', 'sm')}<input id="sb_url" placeholder="https://www.lycamobile.us/en/59-unlimited-plan/" inputmode="url"></div>
        <button class="btn primary" id="sb_go">${icon('spark', 'sm')} Analyze</button></div>
      <div id="sb_out"></div>`;
    const out = $('#sb_out', host);
    const go = async () => {
      const url = $('#sb_url', host).value.trim();
      if (!url) { $('#sb_url', host).focus(); return; }
      out.innerHTML = `<div class="sb-load"><span class="spin"></span><div><b>Analyzing website…</b><div class="muted small">Checking page content, Keyword Planner and conversion tracking (10–30 sec)</div></div></div>`;
      try {
        const r = await A.get('smart_analyze', { url, currency: cur() });
        render(r);
      } catch (e) { out.innerHTML = `<div class="alert err" style="margin:12px 0 0">${esc(e.error || 'Could not analyze')}</div>`; }
    };
    $('#sb_go', host).onclick = go;
    $('#sb_url', host).onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); go(); } };

    function render(r) {
      const S = r.suggest, st = r.site, src = r.sources;
      const bid = { MAXIMIZE_CLICKS: 'Max Clicks', MAXIMIZE_CONVERSIONS: 'Max Conversions', MANUAL_CPC: 'Manual CPC' };
      const n = v => v == null ? '—' : Number(v).toLocaleString('en-IN');
      out.innerHTML = `
        <div class="sb-found">
          <span class="chip on">${icon('case', 'sm')} ${esc(st.category.ai_label || st.category.label)} <span class="muted">· ${esc(st.category.confidence)}</span></span>
          <span class="chip">Brand: <b>${esc(st.brand)}</b></span><span class="chip">${icon('pin', 'sm')} ${esc(st.country.name)}</span>
          <span class="chip">Lang: ${esc(st.lang)}</span>${st.prices.length ? `<span class="chip">Price: ${esc(st.prices.slice(0, 3).join(', '))}</span>` : ''}
          <span class="src ${src.website ? 'ok' : ''}">Website ✓</span><span class="src ${src.keyword_planner ? 'ok' : ''}">Keyword Planner ${src.keyword_planner ? '✓' : '✗'}</span>
          <span class="src ${src.ai ? 'ok' : ''}" title="Set anthropic_api_key in config.php">AI ${src.ai ? '✓' : 'off'}</span>
        </div>
        ${r.notes?.length ? `<div class="alert warn" style="margin:10px 0 0">${r.notes.map(esc).join('<br>')}</div>` : ''}
        <div class="sb-grid">
          <div class="sb-box"><div class="sb-t"><label><input type="checkbox" data-g="setup" checked> Campaign, budget & bidding</label></div>
            <div class="kvs"><div><span>Name</span><b>${esc(S.campaign_name)}</b></div><div><span>Ad group</span><b>${esc(S.ad_group)}</b></div>
            <div><span>Budget/day</span><b>${esc(cur())} ${n(S.budget)}</b></div><div><span>Bidding</span><b>${bid[S.bidding]}</b></div>
            ${S.max_cpc_limit ? `<div><span>Max CPC limit</span><b>${esc(cur())} ${n(S.max_cpc_limit)}</b></div>` : ''}</div>
            <div class="hint">${esc(S.budget_note || '')}${S.bidding_note ? '<br>' + esc(S.bidding_note) : ''}</div></div>
          <div class="sb-box"><div class="sb-t"><label><input type="checkbox" data-g="geo" checked> Locations & language</label></div>
            <div class="kvs"><div><span>Location</span><b>${S.locations.map(g => esc(g.name)).join(', ')}</b></div><div><span>Language</span><b>${S.languages.join(', ') === '1000' ? 'English' : esc(S.languages.length + ' language')}</b></div>
            <div><span>Negatives</span><b>${esc(S.negatives.join(', '))}</b></div></div>
            <label class="small row" style="gap:6px;margin-top:6px"><input type="checkbox" data-g="neg" checked> Add negatives too</label></div>
        </div>
        <div class="sb-box"><div class="sb-t"><label><input type="checkbox" data-all="kw" checked> Keywords <span class="muted">(${S.keywords.length})</span></label>
          <span class="muted small">Volume/CPC ${src.keyword_planner ? 'from Google Keyword Planner' : 'unavailable'}</span></div>
          <div class="table-wrap sb-kw"><table class="data"><thead><tr><th></th><th class="l">Keyword</th><th>Searches/mo</th><th>CPC range</th><th>Competition</th><th class="l">Source</th></tr></thead><tbody>
          ${S.keywords.map((k, i) => `<tr><td class="c"><input type="checkbox" data-kw="${i}" ${i < 20 ? 'checked' : ''}></td><td class="l"><b>${esc(k.text)}</b></td><td>${n(k.volume)}</td>
            <td>${k.cpc_high ? `${n(k.cpc_low)} – ${n(k.cpc_high)}` : '—'}</td><td>${k.competition ? `<span class="comp ${esc(k.competition)}">${esc(label(k.competition))}</span>` : '—'}</td><td class="l muted small">${esc(k.source)}</td></tr>`).join('')}
          </tbody></table></div></div>
        <div class="sb-grid">
          <div class="sb-box"><div class="sb-t"><label><input type="checkbox" data-all="hl" checked> Headlines <span class="muted">(${S.headlines.length})</span></label></div>
            ${S.headlines.map((h, i) => `<label class="sb-line"><input type="checkbox" data-hl="${i}" checked><span>${esc(h)}</span><em>${[...h].length}/30</em></label>`).join('')}</div>
          <div class="sb-box"><div class="sb-t"><label><input type="checkbox" data-all="ds" checked> Descriptions <span class="muted">(${S.descriptions.length})</span></label></div>
            ${S.descriptions.map((h, i) => `<label class="sb-line"><input type="checkbox" data-ds="${i}" checked><span>${esc(h)}</span><em>${[...h].length}/90</em></label>`).join('')}
            <div class="kvs" style="margin-top:10px"><div><span>Final URL</span><b class="url">${esc(S.final_url)}</b></div><div><span>Display path</span><b>/${esc(S.path1)}${S.path2 ? '/' + esc(S.path2) : ''}</b></div></div></div>
        </div>
        <div class="sb-actions"><span class="muted small">After applying, you can edit everything in the form below.</span>
          <button class="btn primary" id="sb_apply">${icon('check', 'sm')} Apply selected suggestions</button></div>`;
      out.onchange = e => {
        const all = e.target.dataset.all;
        if (all) $$(`[data-${all}]`, out).forEach(c => { if (c !== e.target) c.checked = e.target.checked; });
      };
      $('#sb_apply', out).onclick = () => {
        const on = sel => $$(sel, out).filter(c => c.checked).map(c => +Object.values(c.dataset)[0]);
        const g = k => $(`[data-g="${k}"]`, out).checked;
        apply({
          setup: g('setup') ? { name: S.campaign_name, ad_group: S.ad_group, budget: S.budget, bidding: S.bidding, max_cpc_limit: S.max_cpc_limit } : null,
          geo: g('geo') ? { locations: S.locations, languages: S.languages } : null,
          negatives: g('neg') ? S.negatives : null,
          keywords: on('[data-kw]').map(i => S.keywords[i]),
          headlines: on('[data-hl]').map(i => S.headlines[i]),
          descriptions: on('[data-ds]').map(i => S.descriptions[i]),
          ad: { final_url: S.final_url, path1: S.path1, path2: S.path2 },
          conv: r.conversions,
        });
        $$('.sb-found ~ *', out).forEach(x => x.classList.add('hidden'));
        out.insertAdjacentHTML('beforeend', `<div class="sb-done"><span>${icon('check', 'sm')} Suggestions applied. Edit them in the form below.</span><button type="button" class="btn sm" id="sb_show">Show suggestions again</button></div>`);
        $('#sb_show', out).onclick = () => { $$('.sb-found ~ *', out).forEach(x => x.classList.remove('hidden')); $('.sb-done', out).remove(); };
      };
    }
  }

  // ================= Wizard page =================
  const TIPS = {
    s1: ['Put brand + country + type in the name (e.g. "Lyca - US - Search")', 'New campaign: start with Maximize clicks, switch to Maximize conversions once conversions come in', 'Set the budget so you get at least 10–20 clicks/day'],
    s2: ['Only the locations where the offer is valid', 'Presence option: only people who are in the location', 'Schedule: run during the hours that convert best'],
    s3: ['One ad group = one theme (5–20 keywords)', 'Start with Phrase/Exact, add Broad later', 'Add negatives (free, jobs, login) from day one'],
    s5: ['Leave the suffix empty here if you use the Suffix Rotator', 'Smart Bidding needs conversion tracking to work', 'Open the landing URL in a browser once to check it'],
  };
  const tipBox = k => `<div class="tips"><div class="tips-t">${icon('bulb', 'sm')} Tips</div>${TIPS[k].map(t => `<div class="tip">${icon('check', 'sm')}<span>${t}</span></div>`).join('')}</div>`;

  A.views['new-campaign'] = {
    eyebrow: 'Campaigns', dates: false,
    async render(el) {
      if (!APP.canEdit) { el.innerHTML = '<section class="card status-panel"><h2>Changes are disabled</h2><p class="muted">Set allow_changes = true in config.php.</p></section>'; return; }
      if (state.acc?.access === 'view') { el.innerHTML = '<section class="card status-panel"><h2>View-only access</h2><p class="muted">This account is shared with you as "View only". Ask the owner for "Can edit" access to create campaigns.</p></section>'; return; }
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      let L, presetList = [];
      try { L = await langs(); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      try { presetList = (await A.api('presets')).presets || []; } catch { presetList = []; }
      const dr = store.get(DRAFT_KEY()) || {};
      const f = Object.assign({ name: '', budget: '', bidding: 'MAXIMIZE_CLICKS', max_cpc_limit: '', target_cpa: '', cpc_bid: '', search_partners: false,
        geo_type: 'PRESENCE', locations: [L.geos[0]], excluded: [], schedule: [], languages: ['1000'], ad_group: 'Ad group 1', keywords: '', match: 'PHRASE', negatives: '',
        tracking_url_template: '', final_url_suffix: '', ad: {} }, dr);
      const c = cur();
      const ctx = { conv: null };
      el.innerHTML = `<div id="wiz" class="wz">
        <div class="wz-head">
          <div><div class="crumbs"><a href="#campaigns">Campaigns</a> › <span>${esc(state.acc.name)}</span> › <b>New Search campaign</b></div>
            <h1 class="wz-title">New Search campaign</h1>
            <div class="muted small">Account: ${esc(state.acc.name)} · Customer ID: ${esc(A.fmtId(state.acc.id))}${state.demo ? ' · <span class="chip">Demo</span>' : ''}</div></div>
          <div class="wz-actions"><button class="btn" id="w_draft">${icon('save', 'sm')} Save as draft</button>
            <button class="btn primary" id="w_create_top">${icon('send', 'sm')} Create campaign (Paused)</button></div>
        </div>

        <nav class="stepper" id="w_steps">${STEPS.map(([id, t, sub, ic], i) => `<button type="button" data-go="${id}"><span class="sn">${i + 1}</span><span class="st"><b>${t}</b><small>${sub}</small></span></button>`).join('')}
          <div class="step-score" id="w_score" title="Campaign health"></div></nav>

        <div class="wz-top">
          <section class="card sb" id="w_smart"></section>
          <section class="card hc" id="w_health"></section>
        </div>

        <section class="card wz-sec" id="s1"><div class="wz-body">
          <div class="sec-h"><span class="sec-n">1</span><div><h3>Campaign, budget and bidding</h3><p>Choose a name, daily budget and bid strategy.</p></div></div>
          <div class="grid2">
            <div><label class="f">Campaign name</label><input id="w_name" value="${esc(f.name)}" placeholder="e.g. Lyca US - Unlimited Plan - Search"></div>
            <div><label class="f">Daily budget (${esc(c)})</label><div class="in-pre"><span>${esc(c)}</span><input id="w_budget" type="number" min="1" step="0.01" value="${esc(f.budget)}" placeholder="50"></div></div>
          </div>
          <label class="f">Bidding strategy</label>
          <div class="seg" id="w_bid">${[['MAXIMIZE_CLICKS', 'Maximize clicks', 'As many clicks as possible. Best for a new campaign.', 'mouse'], ['MAXIMIZE_CONVERSIONS', 'Maximize conversions', 'When conversion tracking is set up.', 'barchart'], ['MANUAL_CPC', 'Manual CPC', 'You set the bid for each click.', 'gear']]
            .map(([k, t, s2, ic]) => `<label class="seg-o"><input type="radio" name="bid" value="${k}" ${f.bidding === k ? 'checked' : ''}><span class="seg-ic">${icon(ic, 'sm')}</span><b>${t}</b><span>${s2}</span></label>`).join('')}</div>
          <div class="grid2">
            <div data-bid="MAXIMIZE_CLICKS"><label class="f">Max CPC limit <span class="muted">(optional)</span></label><div class="in-pre"><span>${esc(c)}</span><input id="w_cpcl" type="number" step="0.01" min="0" value="${esc(f.max_cpc_limit ?? '')}"></div><div class="hint">Google won't bid more than this for a click.</div></div>
            <div data-bid="MAXIMIZE_CONVERSIONS"><label class="f">Target CPA <span class="muted">(optional)</span></label><div class="in-pre"><span>${esc(c)}</span><input id="w_tcpa" type="number" step="0.01" min="0" value="${esc(f.target_cpa)}"></div><div class="hint">Empty = Google maximizes conversions within the budget.</div></div>
            <div data-bid="MANUAL_CPC"><label class="f">Default max CPC bid</label><div class="in-pre"><span>${esc(c)}</span><input id="w_cpc" type="number" step="0.01" min="0" value="${esc(f.cpc_bid)}"></div></div>
          </div>
          <label class="f row check-row"><input type="checkbox" id="w_sp" ${f.search_partners ? 'checked' : ''}> Include Google search partners <span class="muted">(Display network always OFF)</span></label>
        </div><aside class="wz-aside"><div class="stat-card" id="w_budget_card"></div>${tipBox('s1')}</aside></section>

        <section class="card wz-sec" id="s2"><div class="wz-body">
          <div class="sec-h"><span class="sec-n">2</span><div><h3>Locations, language and schedule</h3><p>Where, in which language and when your ads show.</p></div></div>
          <label class="f">Included locations</label><div id="w_geo"></div>
          <label class="f">Exclude locations <span class="muted">(optional)</span></label><div id="w_ex"></div>
          <label class="f">Campaign presets <span class="muted">(select one or more — e.g. Impact US + AWIN UK)</span></label>
          <div id="w_presets" class="preset-chips"></div>
          <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
            <button type="button" class="btn sm primary" id="w_preset_apply">${icon('check', 'sm')} Apply selected</button>
            <button type="button" class="btn sm" id="w_preset_save">${icon('save', 'sm')} Save current as preset</button>
          </div>
          <div class="hint">Selected presets add their blocked locations above and negative keywords in step 3 (combined). You can still edit per campaign. Manage presets in the <b>Campaign Presets</b> section.</div>
          <label class="f">Location option</label>
          <select id="w_gt"><option value="PRESENCE">Presence: only people in or regularly in your locations (recommended)</option>
            <option value="PRESENCE_OR_INTEREST" ${f.geo_type === 'PRESENCE_OR_INTEREST' ? 'selected' : ''}>Presence or interest: also people interested in your locations</option></select>
          <label class="f">Language</label><div id="w_lang"></div>
          <label class="f">Ad schedule <span class="muted">(optional)</span></label><div id="w_sch"></div>
        </div><aside class="wz-aside"><div class="stat-card" id="w_geo_card"></div>${tipBox('s2')}</aside></section>

        <section class="card wz-sec" id="s3"><div class="wz-body">
          <div class="sec-h"><span class="sec-n">3</span><div><h3>Ad group and keywords</h3><p>Create an ad group and add relevant keywords.</p></div></div>
          <div class="grid2"><div><label class="f">Ad group name</label><input id="w_ag" value="${esc(f.ad_group)}"></div>
            <div><label class="f">Default match type</label><select id="w_mt"><option value="PHRASE">Phrase match "…"</option><option value="EXACT" ${f.match === 'EXACT' ? 'selected' : ''}>Exact match […]</option><option value="BROAD" ${f.match === 'BROAD' ? 'selected' : ''}>Broad match</option></select></div></div>
          <div class="grid2">
            <div><label class="f">Keywords <span class="muted">(one per line)</span> <span class="count-pill" id="w_kc"></span></label>
              <textarea id="w_kw" rows="8" placeholder='lycamobile unlimited plan&#10;[lyca mobile 6 month plan]&#10;"prepaid sim usa"'>${esc(f.keywords)}</textarea></div>
            <div><label class="f">Negative keywords <span class="muted">(optional)</span> <span class="count-pill" id="w_nc"></span></label>
              <textarea id="w_neg" rows="8" placeholder="free&#10;jobs&#10;customer care number">${esc(f.negatives)}</textarea></div>
          </div>
          <div class="hint"><code>[keyword]</code> = Exact, <code>"keyword"</code> = Phrase, otherwise the default match type. You can paste straight from Excel.</div>
          <div id="w_kprev" class="kprev"></div>
        </div><aside class="wz-aside"><div class="stat-card" id="w_kw_card"></div>${tipBox('s3')}</aside></section>

        <section class="card wz-sec wide" id="s4"><div class="wz-body">
          <div class="sec-h"><span class="sec-n">4</span><div><h3>Responsive search ad</h3><p>Add several headlines and descriptions; Google shows the best combination.</p></div></div>
          <div id="w_rsa"></div>
        </div></section>

        <section class="card wz-sec" id="s5"><div class="wz-body">
          <div class="sec-h"><span class="sec-n">5</span><div><h3>Tracking <span class="muted" style="font-weight:500;font-size:13px">(optional)</span></h3><p>Campaign-level final URL suffix and tracking template.</p></div></div>
          <label class="f">Final URL suffix <span class="muted">(no leading ?)</span></label>
          <input id="w_sfx" value="${esc(f.final_url_suffix)}" placeholder="irclickid=...&utm_source=impact&afsrc=1">${A.vtChips('w_sfx')}
          <label class="f">Tracking template</label>
          <input id="w_tpl" value="${esc(f.tracking_url_template)}" placeholder="{lpurl}?utm_campaign={campaignid}">${A.vtChips('w_tpl')}
          <div class="final-url" id="w_full"></div>
        </div><aside class="wz-aside"><div class="stat-card" id="w_conv_card"></div>${tipBox('s5')}</aside></section>

        <div class="wiz-bar"><div class="wb-l"><span id="w_mini"></span><div class="muted small" id="w_sum"></div></div>
          <div class="wb-r"><button class="btn" id="w_check" title="Validate with Google">${icon('shield', 'sm')}<span class="hide-sm"> Validate with Google</span></button>
          <button class="btn primary" id="w_create">${icon('send', 'sm')} Create campaign (Paused)</button></div></div></div>`;

      const root = $('#wiz'); // #view stays the same across pages, so bind listeners on the inner div
      A.bindVt(root);
      const geo = geoPicker($('#w_geo'), f.locations.filter(Boolean), L.geos);
      const ex = geoPicker($('#w_ex'), f.excluded || [], [], { neg: true, empty: 'No exclusions' });
      const sch = scheduleEditor($('#w_sch'), f.schedule || []);
      const lang = langPicker($('#w_lang'), L.languages, f.languages);
      const rsa = rsaEditor($('#w_rsa'), f.ad);
      const bid = () => $('input[name=bid]:checked', root).value;

      // ----- Campaign presets (select one or more; merge excluded locations + negatives) -----
      const dedupeGeo = list => { const m = new Map(); list.forEach(g => m.set(String(g.id), g)); return [...m.values()]; };
      const fillPresets = () => {
        $('#w_presets').innerHTML = presetList.length
          ? presetList.map(p => `<label class="preset-chip"><input type="checkbox" value="${p.id}"> ${esc(p.name)} <span class="muted">(${p.excluded.length} loc${p.negatives ? ', neg' : ''})</span></label>`).join('')
          : '<span class="muted small">No presets yet. Create them in the Campaign Presets section, or save the current setup below.</span>';
      };
      fillPresets();
      $('#w_preset_apply').onclick = () => {
        const ids = $$('#w_presets input:checked', root).map(i => i.value);
        if (!ids.length) { A.toast('Select at least one preset'); return; }
        let geos = ex.get(); const negLines = [];
        ids.forEach(id => { const p = presetList.find(x => String(x.id) === id); if (!p) return; geos = geos.concat(p.excluded || []); if (p.negatives) negLines.push(p.negatives); });
        ex.set(dedupeGeo(geos));
        if (negLines.length) { const cur = $('#w_neg').value.trim(); $('#w_neg').value = (cur ? cur + '\n' : '') + negLines.join('\n'); }
        update();
        A.toast(`${ids.length} preset${ids.length > 1 ? 's' : ''} applied`);
      };
      $('#w_preset_save').onclick = async () => {
        const name = (prompt('Preset name (e.g. Impact US, AWIN UK):') || '').trim();
        if (!name) return;
        const excluded = ex.get(), negatives = $('#w_neg').value;
        try {
          const r = await A.api('preset_save', {}, { name, excluded, negatives });
          const existing = presetList.find(p => p.id === r.id);
          if (existing) { existing.name = name; existing.excluded = excluded; existing.negatives = negatives; }
          else presetList.push({ id: r.id, name, excluded, negatives });
          fillPresets();
          A.toast(`Preset "${name}" saved`);
        } catch (err) { A.showError(err); }
      };

      const collect = () => ({
        name: $('#w_name').value.trim(), budget: $('#w_budget').value, bidding: bid(),
        max_cpc_limit: $('#w_cpcl').value, target_cpa: $('#w_tcpa').value, cpc_bid: $('#w_cpc').value, search_partners: $('#w_sp').checked,
        geo_type: $('#w_gt').value, locations: geo.get(), excluded: ex.get(), schedule: sch.get(), languages: lang.get(),
        ad_group: $('#w_ag').value.trim(), keywords: $('#w_kw').value, match: $('#w_mt').value, negatives: $('#w_neg').value,
        tracking_url_template: $('#w_tpl').value.trim(), final_url_suffix: $('#w_sfx').value.trim().replace(/^[?&]+/, ''), ad: rsa.get(),
      });
      const payload = d => ({ ...d, locations: d.locations.map(g => g.id), excluded: d.excluded.map(g => g.id) });
      let H = null;

      function update() {
        const d = collect();
        H = health(d, ctx);
        $$('[data-bid]', root).forEach(x => x.classList.toggle('hidden', x.dataset.bid !== d.bidding));
        const kws = H.kws;
        $('#w_kc').textContent = kws.length;
        $('#w_nc').textContent = H.negs.length;
        const sym = { EXACT: ['[', ']'], PHRASE: ['"', '"'], BROAD: ['', ''] };
        $('#w_kprev').innerHTML = kws.slice(0, 40).map(k => `<span class="chip kw-${k.m}">${esc(sym[k.m][0] + k.t + sym[k.m][1])}</span>`).join('') + (kws.length > 40 ? `<span class="muted small">+${kws.length - 40} more</span>` : '');
        const u = d.ad.final_url;
        $('#w_full').innerHTML = u ? `<div class="small muted">On click, the landing URL becomes:</div><code>${esc(u + (d.final_url_suffix ? (u.includes('?') ? '&' : '?') + d.final_url_suffix : ''))}</code>` : '';

        // side cards
        const b = +d.budget || 0;
        const cpc = d.bidding === 'MANUAL_CPC' ? +d.cpc_bid : +d.max_cpc_limit;
        $('#w_budget_card').innerHTML = `<div class="sc-t">${icon('wallet', 'sm')} Budget estimate</div>
          <div class="sc-row"><span>Daily</span><b>${b ? money(b) : '—'}</b></div><div class="sc-row"><span>Monthly (max)</span><b>${b ? money(b * 30.4) : '—'}</b></div>
          ${cpc > 0 && b ? `<div class="sc-row"><span>Clicks/day (approx)</span><b>~${Math.max(1, Math.floor(b / cpc))}+</b></div>` : ''}`;
        const hrs = (() => { if (!d.schedule.length) return 168; let m = 0; d.schedule.forEach(r => { const n = r.day === 'ALL' ? 7 : r.day === 'WEEKDAYS' ? 5 : r.day === 'WEEKEND' ? 2 : 1; m += Math.max(0, mins2(r.end) - mins2(r.start)) * n; }); return Math.round(m / 60); })();
        $('#w_geo_card').innerHTML = `<div class="sc-t">${icon('pin', 'sm')} Targeting summary</div>
          <div class="sc-stats"><div><b>${d.locations.length}</b><span>Locations</span></div><div><b>${d.excluded.length}</b><span>Excluded</span></div><div><b>${d.languages.length || 'All'}</b><span>Languages</span></div><div><b>${hrs}h</b><span>Per week</span></div></div>
          <div class="sc-list">${d.locations.slice(0, 5).map(g => `<span class="chip on">${esc(gname(g.name))}</span>`).join('')}</div>`;
        const cnt = m => kws.filter(k => k.m === m).length;
        $('#w_kw_card').innerHTML = `<div class="sc-t">${icon('tag', 'sm')} Keyword stats</div>
          <div class="sc-stats"><div><b>${kws.length}</b><span>Keywords</span></div><div><b>${cnt('EXACT')}</b><span>Exact</span></div><div><b>${cnt('PHRASE')}</b><span>Phrase</span></div><div><b>${cnt('BROAD')}</b><span>Broad</span></div></div>
          <div class="sc-row"><span>Negatives</span><b>${H.negs.length}</b></div>`;
        $('#w_conv_card').innerHTML = `<div class="sc-t">${icon('target', 'sm')} Conversion tracking</div>${ctx.conv == null ? '<div class="muted small">Checking…</div>'
          : ctx.conv.count ? `<div class="conv ok">${icon('check', 'sm')} ${ctx.conv.count} conversion action active</div><div class="muted small">${ctx.conv.names.slice(0, 3).map(esc).join(', ')}</div>`
          : `<div class="conv bad">${icon('alert', 'sm')} No conversion action</div><div class="muted small">Set one up in the Conversions page. Use Maximize clicks until then.</div>`}`;
        // ad strength
        $('.rsa-strength', root).innerHTML = `<div class="as-row"><span>Ad strength</span><b class="as-${H.adLabel}">${H.adLabel}</b></div><div class="bar"><i class="${tone(H.parts.ad)}" style="width:${H.parts.ad}%"></i></div>`;

        // health card
        const P = H.parts, errs = H.issues.filter(x => x.lvl === 'err').length, warns = H.issues.length - errs;
        const bars = [['Targeting', P.targeting], ['Keywords', P.keywords], ['Ad strength', P.ad], ['Tracking', P.tracking], ['Budget & bidding', P.setup]];
        $('#w_health').innerHTML = `<div class="hc-head"><div><h3>${icon('gauge', 'sm')} Campaign health</h3><p class="muted small">${errs ? `<b class="t-bad">${errs} error</b> · ` : ''}${warns} suggestion${warns === 1 ? '' : 's'}</p></div>${ring(H.overall)}</div>
          <div class="hc-bars">${bars.map(([n, v]) => `<div class="hb"><span>${n}</span><div class="bar"><i class="${tone(v)}" style="width:${v}%"></i></div><b>${v}%</b></div>`).join('')}</div>
          <div class="hc-issues">${H.issues.length ? H.issues.slice(0, 7).map(x => `<button type="button" class="iss ${x.lvl}" data-go="${x.step}">${icon(x.lvl === 'err' ? 'alert' : 'info', 'sm')}<span>${esc(x.text)}</span></button>`).join('')
            + (H.issues.length > 7 ? `<div class="muted small" style="padding:4px 2px">+${H.issues.length - 7} more (in the sections below)</div>` : '')
            : `<div class="iss ok">${icon('check', 'sm')}<span>All good! Ready to create the campaign.</span></div>`}</div>`;
        $('#w_score').innerHTML = `<span class="dot ${tone(H.overall)}"></span><b>${H.overall}%</b><small>health</small>`;
        $('#w_mini').innerHTML = `<span class="mini-score ${tone(H.overall)}">${H.overall}%</span>`;
        STEPS.forEach(([id]) => {
          const bad = H.issues.some(x => x.step === id && x.lvl === 'err');
          const b2 = $(`[data-go="${id}"]`, $('#w_steps'));
          b2.classList.toggle('done', !bad); b2.classList.toggle('bad', bad);
        });
        $('#w_sum').innerHTML = `${d.name ? '<b>' + esc(d.name) + '</b> · ' : ''}${d.budget ? money(+d.budget) + '/day · ' : ''}${esc(label(d.bidding))} · ${d.locations.length} location · ${kws.length} keywords · ${d.ad.headlines.length} headlines`;
        ['w_create', 'w_create_top'].forEach(id => $('#' + id).classList.toggle('blocked', errs > 0));
        store.set(DRAFT_KEY(), d);
      }
      root.addEventListener('input', update);
      root.addEventListener('change', update);
      update();

      // conversion tracking status (background)
      A.get('conversion_status').then(r => { ctx.conv = r; update(); }).catch(() => { ctx.conv = null; });

      // stepper: click -> scroll, scroll -> active
      root.addEventListener('click', e => {
        const g = e.target.closest('[data-go]');
        if (g) { const t = $('#' + g.dataset.go); t && t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
      });
      if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(ents => {
          ents.forEach(en => { if (en.isIntersecting) $$('#w_steps [data-go]').forEach(b2 => b2.classList.toggle('on', b2.dataset.go === en.target.id)); });
        }, { rootMargin: '-35% 0px -55% 0px' });
        STEPS.forEach(([id]) => io.observe($('#' + id)));
      }

      // smart builder
      smartPanel($('#w_smart'), s => {
        if (s.setup) {
          $('#w_name').value = s.setup.name; $('#w_ag').value = s.setup.ad_group; $('#w_budget').value = s.setup.budget;
          const r = $(`input[name=bid][value="${s.setup.bidding}"]`, root); if (r) r.checked = true;
          if (s.setup.max_cpc_limit) $('#w_cpcl').value = s.setup.max_cpc_limit;
        }
        if (s.geo) { geo.set(s.geo.locations); lang.set(s.geo.languages); }
        if (s.negatives) $('#w_neg').value = s.negatives.join('\n');
        if (s.keywords.length) {
          const sym = { EXACT: k => `[${k}]`, PHRASE: k => `"${k}"`, BROAD: k => k };
          $('#w_kw').value = s.keywords.map(k => sym[k.match || 'PHRASE'](k.text)).join('\n');
        }
        rsa.set({ ...s.ad, ...(s.headlines.length ? { headlines: s.headlines } : {}), ...(s.descriptions.length ? { descriptions: s.descriptions } : {}) });
        if (s.conv) ctx.conv = s.conv;
        update();
        A.toast('Suggestions filled into the form — review and edit');
        $('#s1').scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      if (f.ad?.final_url) $('#sb_url').value = f.ad.final_url;

      $('#w_draft').onclick = () => { store.set(DRAFT_KEY(), collect()); A.toast('Draft saved (in this browser)'); };
      $('#w_check').onclick = e => A.busy(e.currentTarget, async () => {
        try {
          const r = await A.post('create_campaign', { data: payload(collect()), validate_only: true });
          A.showError(null);
          A.toast(`Google check passed (${r.keywords} keywords)`);
        } catch (err) { A.showError(err); window.scrollTo({ top: 0, behavior: 'smooth' }); }
      });
      const create = async e => {
        const d = collect();
        const btn = e.currentTarget;
        const errs = H.issues.filter(x => x.lvl === 'err');
        if (errs.length) { A.toast(`Fix ${errs.length} error(s) first`); $('#w_health').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
        if (!await A.confirmBox(`Create campaign <b>${esc(d.name)}</b>?<br><span class="muted small">${money(+d.budget || 0)}/day · ${esc(label(d.bidding))} · Health ${H.overall}% · created PAUSED</span>`, 'Create')) return;
        await A.busy(btn, async () => {
          try {
            const r = await A.post('create_campaign', { data: payload(d) });
            store.del(DRAFT_KEY()); state.report = null; A.showError(null);
            done(el, r);
          } catch (err) { A.showError(err); window.scrollTo({ top: 0, behavior: 'smooth' }); }
        });
      };
      $('#w_create').onclick = create;
      $('#w_create_top').onclick = create;
    },
  };

  function done(el, r) {
    el.innerHTML = `<section class="card status-panel" style="text-align:center">
      <div class="done-ic">${icon('check')}</div>
      <h2>Campaign created</h2>
      <p class="muted"><b>${esc(r.name)}</b> · ${r.keywords} keywords · status <b>Paused</b></p>
      <p class="muted small">Google reviews the new ad (usually a few hours). Even after you enable it, the ad won't run until it's approved.</p>
      <div class="row" style="gap:10px;justify-content:center;margin-top:16px">
        <a class="btn" href="#campaign/${r.campaign_id}/settings">${icon('edit', 'sm')} Open campaign</a>
        <button class="btn primary" id="enNow">${icon('play', 'sm')} Enable now</button>
        <a class="btn ghost" href="#new-campaign">${icon('plus', 'sm')} Create another</a></div></section>`;
    $('#enNow').onclick = async e => {
      if (!await A.confirmBox(`Enable <b>${esc(r.name)}</b>? This will start spending money.`, 'Enable')) return;
      const b = $('#enNow');
      await A.busy(b, async () => {
        try { await A.post('set_campaign_status', { campaign_id: r.campaign_id, status: 'ENABLED', name: r.name }); A.toast('Campaign enabled'); location.hash = `#campaign/${r.campaign_id}/settings`; }
        catch (err) { A.showError(err); }
      });
    };
  }

  // ================= Existing campaign: new RSA ad =================
  A.rsaModal = (c, ags, done, init = {}) => {
    const m = A.modal({
      title: 'New responsive search ad', wide: true,
      body: `<div class="grid2"><div><label class="f">Ad group</label><select id="r_ag">${ags.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select></div>
        <div><label class="f">Status</label><select id="r_st"><option value="ENABLED">Active</option><option value="PAUSED">Paused</option></select></div></div>
        <div id="r_ed"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="r_sv">${icon('plus', 'sm')} Create ad</button>`,
    });
    const ed = rsaEditor(m.$('#r_ed'), init);
    m.$('#r_sv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        await A.post('create_rsa', { ag_id: m.$('#r_ag').value, data: { ...ed.get(), status: m.$('#r_st').value } });
        m.close(); A.toast('Ad created (Google will review it)'); done();
      } catch (err) { const b = m.$('#r_err') || (m.$('.modal-body').insertAdjacentHTML('afterbegin', '<div class="alert err" id="r_err" style="margin-top:12px"></div>'), m.$('#r_err')); b.textContent = err.error || 'Error'; m.$('.modal-body').scrollTop = 0; }
    });
  };

  // ================= Existing campaign: Locations & languages tab =================
  A.targetingTab = async (el, c) => {
    el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
    let t, L;
    try { [t, L] = await Promise.all([A.get('targeting', { campaign_id: c.id }), langs()]); }
    catch (e) { A.showError(e); el.innerHTML = ''; return; }
    if (t.blocked) { el.innerHTML = A.blockedHtml(t.blocked); return; }
    const ro = !APP.canEdit;
    el.innerHTML = `<section class="card"><div class="form-card">
      <div class="section-t">Locations</div><div id="t_geo"></div>
      <label class="f">Exclude locations <span class="muted">(ads will NOT show here)</span></label><div id="t_ex"></div>
      <label class="f">Location option</label>
      <select id="t_gt" style="max-width:520px"><option value="PRESENCE">Presence: only people in your locations (recommended)</option>
        <option value="PRESENCE_OR_INTEREST" ${t.geo_type === 'PRESENCE_OR_INTEREST' ? 'selected' : ''}>Presence or interest</option></select>
      <div class="section-t">Languages</div><div id="t_lang"></div>
      <div class="section-t">Ad schedule</div><div id="t_sch"></div>
      <div class="save-bar"><button class="btn" id="t_reset">Reset</button><button class="btn primary" id="t_save" ${ro ? 'disabled' : ''}>${icon('check', 'sm')} Save targeting</button></div>
    </div></section>`;
    const geo = geoPicker($('#t_geo'), t.locations, L.geos);
    const ex = geoPicker($('#t_ex'), t.excluded || [], [], { neg: true, empty: 'No exclusions' });
    const sch = scheduleEditor($('#t_sch'), (t.schedule || []).map(r => ({ day: r.day, start: r.start, end: r.end })));
    const lang = langPicker($('#t_lang'), L.languages, t.languages.map(x => x.id));
    $('#t_reset').onclick = () => A.targetingTab(el, c);
    $('#t_save').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        const r = await A.post('update_targeting', { campaign_id: c.id, name: c.name, data: { locations: geo.get().map(g => g.id), excluded: ex.get().map(g => g.id), languages: lang.get(), schedule: sch.get(), geo_type: $('#t_gt').value } });
        A.toast(r.added || r.removed || r.geo_type_changed ? `Targeting saved (+${r.added} / −${r.removed})` : 'Nothing changed');
        A.showError(null);
      } catch (err) { A.showError(err); }
    });
  };

  // Exposed so other views (e.g. Campaign Presets) can reuse the location picker.
  A.geoPicker = geoPicker;
})();
