/* Dashboard (Accounts) + Campaigns list */
(() => {
  const { $, $$, esc, icon, state, num, dec, money, derive, sum, statusPill } = A;
  let chart = null;
  let metric = 'cost';

  const KPIS = [
    ['cost', 'Cost', 't-blue', null, false],
    ['impr', 'Impressions', 't-purple', 'eye', true],
    ['clicks', 'Clicks', 't-green', 'mouse', true],
    ['ctr', 'CTR', 't-orange', 'pie', true],
    ['cpc', 'Avg CPC', 't-cyan', 'barchart', false],
    ['conv', 'Conversions', 't-pink', 'funnel', true],
    ['cpa', 'CPA', 't-teal', 'target', false],
    ['roas', 'ROAS', 't-violet', 'trend', true],
  ];
  const INK = { 't-blue': '#2563eb', 't-purple': '#7c3aed', 't-green': '#16a34a', 't-orange': '#ea580c', 't-cyan': '#0891b2', 't-pink': '#db2777', 't-teal': '#059669', 't-violet': '#6d28d9' };
  const fmtK = (k, v) => ['cost', 'cpc', 'cpa'].includes(k) ? money(v) : k === 'ctr' ? dec(v) + '%' : k === 'roas' ? dec(v) + 'x' : k === 'conv' ? dec(v) : num(v);

  /** Report load (cache per account+range) */
  async function loadReport(force) {
    const ck = `${state.acc.conn}:${state.acc.id}:${state.from}:${state.to}`;
    if (!force && state.report && state.report._ck === ck) return state.report;
    const r = await A.get('report');
    if (r.blocked) return r;
    r._ck = ck;
    r.campaigns = r.campaigns.map(derive);
    state.report = r;
    return r;
  }
  A.loadReport = loadReport;

  function kpiHtml(r) {
    const t = derive(r.totals), p = derive(r.previous), days = r.daily.map(derive);
    const curIcon = r.currency === 'INR' ? 'rupee' : 'dollar';
    return KPIS.map(([k, lbl, tint, ic, upGood]) => {
      let d = '<span class="delta flat">—</span>';
      if (p[k] > 0) {
        const ch = (t[k] - p[k]) / p[k] * 100;
        const cls = Math.abs(ch) < 0.5 || upGood === null ? 'flat' : ((ch > 0) === upGood ? 'up' : 'down');
        const cls2 = k === 'cost' ? (ch >= 0 ? 'up' : 'down') : cls; // cost: neutral meaning, arrow colour by direction
        d = `<span class="delta ${cls2}">${ch > 0 ? '▲' : ch < 0 ? '▼' : ''} ${dec(Math.abs(ch), 1)}% <small>vs. prev.</small></span>`;
      }
      return `<div class="kpi ${tint}"><div class="ic">${icon(ic || curIcon)}</div>
        <div class="body"><div class="lbl">${lbl}</div><div class="val">${fmtK(k, t[k])}</div>${d}</div>
        ${A.spark(days.map(x => x[k] || 0), INK[tint])}</div>`;
    }).join('');
  }

  function drawChart(canvas, daily) {
    if (!window.Chart) { canvas.parentElement.innerHTML = '<p class="muted" style="padding:20px">Chart library failed to load</p>'; return; }
    const labels = daily.map(x => new Date(x.date + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }));
    const isMoney = metric === 'cost' || metric === 'value';
    const g = canvas.getContext('2d').createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, 'rgba(37,99,235,.22)'); g.addColorStop(1, 'rgba(37,99,235,0)');
    if (chart) chart.destroy();
    chart = new Chart(canvas, {
      type: 'line',
      data: { labels, datasets: [{ data: daily.map(x => x[metric]), borderColor: '#2563eb', backgroundColor: g, fill: true, tension: .35,
        pointRadius: daily.length > 45 ? 0 : 4, pointBackgroundColor: '#2563eb', pointBorderColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 6, borderWidth: 2 }] },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', padding: 10, displayColors: false,
          callbacks: { label: c => (isMoney ? money(c.parsed.y) : metric === 'conv' ? dec(c.parsed.y) : num(c.parsed.y)) } } },
        scales: {
          x: { grid: { color: '#f1f5f9' }, ticks: { maxTicksLimit: 9, color: '#64748b' } },
          y: { beginAtZero: true, grid: { color: '#eef2f7' }, border: { display: false }, ticks: { color: '#64748b', callback: v => isMoney ? money(v) : num(v) } },
        },
      },
    });
  }

  /** Campaign table (dashboard + campaigns page dono me) */
  // Clone a campaign into one or more other accounts (own or shared-with-edit / MCC accounts)
  async function cloneModal(r) {
    let targets;
    try { targets = (await A.api('clone_targets')).targets || []; }
    catch (e) { return A.showError(e); }
    const srcCid = state.acc?.id;
    const rowsHtml = targets.map(t => `<label class="list-item" style="cursor:pointer;gap:10px;align-items:center">
        <input type="checkbox" data-t="${esc(t.conn)}|${esc(t.cid)}" data-name="${esc(t.name)}">
        <div class="grow"><div class="t">${esc(t.name)}${t.cid === srcCid ? ' <span class="badge">source</span>' : ''}</div>
          <div class="muted small">${esc(A.fmtId(t.cid))}${t.currency ? ' · ' + esc(t.currency) : ''}${t.access === 'edit' ? ' · shared (edit)' : ''}${t.email ? ' · ' + esc(t.email) : ''}</div></div></label>`).join('');
    const m = A.modal({
      title: 'Clone campaign',
      body: `<p class="muted small" style="margin:0 0 12px">Copies <b>${esc(r.name)}</b> — ad groups, keywords, ads, targeting and negatives — into the accounts you pick. New campaigns are created <b>paused</b> so you can review before enabling.</p>
        <label class="f">New campaign name <span class="muted">(blank = "… (copy)")</span></label>
        <input id="clName" placeholder="${esc(r.name)} (copy)">
        <label class="f">Daily budget <span class="muted">(blank = same as source)</span></label>
        <input id="clBudget" inputmode="decimal" placeholder="same as source">
        <label class="f" style="margin-top:12px">Destination accounts</label>
        <div style="max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:10px">${rowsHtml || '<p class="muted" style="padding:16px;margin:0">No accounts found. Connect or sync an account first.</p>'}</div>
        <div class="alert err" id="clErr" style="display:none;margin-top:12px"></div>
        <div id="clRes" style="margin-top:12px"></div>`,
      foot: `<button class="btn" data-close>Close</button><button class="btn primary" id="clGo">Clone</button>`,
    });
    m.$('#clGo').onclick = e => A.busy(e.currentTarget, async () => {
      const dests = [...m.el.querySelectorAll('[data-t]:checked')].map(c => {
        const [conn, cid] = c.dataset.t.split('|');
        return { conn, cid, name: c.dataset.name };
      });
      const err = m.$('#clErr');
      err.style.display = 'none';
      if (!dests.length) { err.style.display = 'block'; err.textContent = 'Pick at least one destination account.'; return; }
      try {
        const res = await A.post('campaign_clone', {
          campaign_id: r.id, name: m.$('#clName').value,
          budget: m.$('#clBudget').value ? parseFloat(m.$('#clBudget').value) : 0, destinations: dests,
        });
        const list = res.results.map(x => x.ok
          ? `<div class="list-item"><div class="grow"><div class="t" style="color:var(--ok)">✓ ${esc(x.name)}</div><div class="muted small">${x.ad_groups} ad groups · ${x.keywords} keywords · ${x.ads} ads${(x.warnings && x.warnings.length) ? ' · ⚠ ' + esc(x.warnings.join(' ')) : ''}</div></div></div>`
          : `<div class="list-item"><div class="grow"><div class="t" style="color:var(--bad)">✕ ${esc(x.name)}</div><div class="muted small">${esc(x.error || 'Failed')}</div></div></div>`).join('');
        m.$('#clRes').innerHTML = `<div class="alert info" style="margin:0 0 8px"><b>Done.</b> New campaigns are paused — review and enable them in each account.</div>${list}`;
        A.toast('Clone finished');
      } catch (err2) { err.style.display = 'block'; err.textContent = err2.error || 'Error'; }
    });
  }

  function campaignTable(host, rows, { onChange } = {}) {
    const canEdit = APP.canEdit;
    host.innerHTML = `<div class="card-head"><h2>Campaigns <span class="pill" id="cCount">0</span></h2>
      <div class="tools"><div class="search-in">${icon('search', 'sm')}<input id="cQ" placeholder="Filter campaigns…"></div>
      <select id="cSt"><option value="">All status</option><option value="ENABLED">Active</option><option value="PAUSED">Paused</option></select>
      ${canEdit ? `<a class="btn primary" href="#new-campaign">${icon('plus', 'sm')} New campaign</a>` : ''}</div></div>
      <div id="cBulk" class="bulkbar hidden"></div><div class="table-wrap" id="cTbl"></div>`;
    const sel = new Set();
    const t = new A.Table($('#cTbl', host), [
      { k: 'sel', label: '<input type="checkbox" id="cAll">', cls: 'c', sort: false, html: r => `<input type="checkbox" data-sel="${r.id}" ${sel.has(r.id) ? 'checked' : ''}>` },
      { k: 'name', label: 'Campaign', cls: 'l', html: r => `<a class="nm" href="#campaign/${r.id}" title="${esc(r.name)}">${esc(r.name)}</a><span class="sub">${esc(A.label(r.type))}${r.bidding ? ' · ' + esc(A.label(r.bidding)) : ''}</span>` },
      { k: 'status', label: 'Status', cls: 'l', html: r => statusPill(r.status) },
      { k: 'budget', label: 'Budget/day', html: r => r.budget ? money(r.budget) : '—' },
      ...A.metricCols(),
      { k: 'act', label: '', sort: false, html: r => `<button class="kebab" data-menu="${r.id}" aria-label="Actions">${icon('dots')}</button>` },
    ], {
      rows, sortKey: 'cost', empty: 'No campaigns found',
      filter: r => r.name.toLowerCase().includes($('#cQ', host).value.toLowerCase()) && (!$('#cSt', host).value || r.status === $('#cSt', host).value),
      footer: rs => A.totalsRow(rs, 3, 1).replace('<td class="l">', '<td></td><td class="l">'),
      after: rs => {
        $('#cCount', host).textContent = rs.length;
        const all = $('#cAll', host); if (all) all.checked = rs.length && rs.every(r => sel.has(r.id));
        bulkBar();
      },
    });
    t.render();
    $('#cQ', host).oninput = () => t.render();
    $('#cSt', host).onchange = () => t.render();

    function bulkBar() {
      const b = $('#cBulk', host);
      if (!sel.size || !canEdit) { b.classList.add('hidden'); return; }
      b.classList.remove('hidden');
      b.innerHTML = `${sel.size} selected <button class="btn sm" data-bulk="PAUSED">${icon('pause', 'sm')} Pause</button>
        <button class="btn sm" data-bulk="ENABLED">${icon('play', 'sm')} Enable</button>
        <button class="btn sm" data-bulk="track">${icon('link', 'sm')} Tracking template / suffix</button>
        <button class="btn sm ghost" data-bulk="clear">Clear</button>`;
    }

    host.addEventListener('change', e => {
      if (e.target.id === 'cAll') { t.visible.forEach(r => e.target.checked ? sel.add(r.id) : sel.delete(r.id)); t.render(); }
      if (e.target.dataset.sel) { e.target.checked ? sel.add(e.target.dataset.sel) : sel.delete(e.target.dataset.sel); bulkBar(); }
    });
    host.addEventListener('click', async e => {
      const m = e.target.closest('[data-menu]');
      if (m) { e.stopPropagation(); menu(m, rows.find(r => r.id === m.dataset.menu)); return; }
      const b = e.target.closest('[data-bulk]');
      if (!b) return;
      const act = b.dataset.bulk;
      if (act === 'clear') { sel.clear(); t.render(); return; }
      if (act === 'track') { A.trackingModal([...sel]); return; }
      const list = rows.filter(r => sel.has(r.id) && r.status !== act);
      if (!list.length) return A.toast('All selected campaigns are already ' + (act === 'PAUSED' ? 'paused' : 'enabled'));
      if (!await A.confirmBox(`<b>${act === 'PAUSED' ? 'Pause' : 'Enable'}</b> ${list.length} campaign(s)?`)) return;
      let ok = 0;
      for (const r of list) {
        try { await A.post('set_campaign_status', { campaign_id: r.id, status: act, name: r.name }); r.status = act; ok++; }
        catch (err) { A.showError(err); break; }
      }
      sel.clear(); t.render(); A.toast(`${ok} campaign(s) updated`);
      onChange && onChange();
    });

    function menu(btn, r) {
      const p = A.openPop(btn, `
        <a class="opt" href="#campaign/${r.id}/settings">${icon('gear', 'sm')} Settings & tracking</a>
        <a class="opt" href="#campaign/${r.id}/adgroups">${icon('db', 'sm')} Ad groups</a>
        <a class="opt" href="#campaign/${r.id}/ads">${icon('link', 'sm')} Ads & final URLs</a>
        <a class="opt" href="#campaign/${r.id}/keywords">${icon('key', 'sm')} Keywords</a>
        <a class="opt" href="#campaign/${r.id}/terms">${icon('search', 'sm')} Search terms & negatives</a>
        ${canEdit ? `<div class="sep"></div><button class="opt" data-clone>${icon('copy', 'sm')} Clone to another account…</button>
        <button class="opt" data-toggle>${r.status === 'ENABLED' ? icon('pause', 'sm') + ' Pause campaign' : icon('play', 'sm') + ' Enable campaign'}</button>` : ''}`);
      p.style.minWidth = '240px';
      p.querySelectorAll('a').forEach(a => a.addEventListener('click', A.closePop));
      const cl = p.querySelector('[data-clone]');
      if (cl) cl.onclick = () => { A.closePop(); cloneModal(r); };
      const tg = p.querySelector('[data-toggle]');
      if (tg) tg.onclick = async () => {
        A.closePop();
        const next = r.status === 'ENABLED' ? 'PAUSED' : 'ENABLED';
        if (!await A.confirmBox(`<b>${next === 'PAUSED' ? 'Pause' : 'Enable'}</b> "${esc(r.name)}"?`)) return;
        try { await A.post('set_campaign_status', { campaign_id: r.id, status: next, name: r.name }); r.status = next; t.render(); A.toast('Campaign ' + (next === 'PAUSED' ? 'paused' : 'enabled') + ' ✓'); }
        catch (err) { A.showError(err); }
      };
    }
    return t;
  }
  A.campaignTable = campaignTable;

  function exportCampaigns() {
    const r = state.report; if (!r) return;
    A.csvDownload(`campaigns_${state.acc.id}_${state.from}_to_${state.to}.csv`,
      ['Campaign ID', 'Campaign', 'Type', 'Bidding', 'Status', 'Budget/day', 'Impressions', 'Clicks', 'CTR %', 'Avg CPC', 'Cost', 'Conversions', 'CPA', 'Conv value', 'ROAS'],
      r.campaigns.map(c => [c.id, c.name, c.type, c.bidding, c.status, c.budget, c.impr, c.clicks, c.ctr.toFixed(2), c.cpc.toFixed(2), c.cost.toFixed(2), c.conv.toFixed(2), c.cpa.toFixed(2), c.value.toFixed(2), c.roas.toFixed(2)]));
  }

  // ---------- views ----------
  A.views.dashboard = {
    eyebrow: 'Accounts', csv: exportCampaigns,
    async render(el) {
      el.innerHTML = `<section class="kpis" id="kpis">${KPIS.map(k => `<div class="kpi ${k[2]}"><div class="body"><div class="lbl">${k[1]}</div><div class="val"><span class="spin"></span></div></div></div>`).join('')}</section>
        <section class="card"><div class="card-head"><h2>${icon('barchart')} Performance trend</h2>
          <div class="seg" id="mSeg">${[['cost', 'Cost'], ['clicks', 'Clicks'], ['conv', 'Conversions'], ['value', 'Conv. value'], ['impr', 'Impressions']].map(([k, l]) => `<button data-m="${k}" class="${metric === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
          <div class="chart-box"><canvas id="trend"></canvas></div></section>
        <section class="card" id="camps"><div class="card-head"><h2>Campaigns</h2></div><p class="empty" style="padding:30px;text-align:center"><span class="spin"></span></p></section>`;
      let r;
      try { r = await loadReport(); } catch (e) { A.showError(e); $('#kpis').innerHTML = ''; return; }
      if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
      if (A.state.view !== 'dashboard' || !$('#kpis')) return;
      $('#kpis').innerHTML = kpiHtml(r);
      drawChart($('#trend'), r.daily);
      $('#mSeg').onclick = e => {
        const b = e.target.closest('button'); if (!b) return;
        metric = b.dataset.m; $$('#mSeg button').forEach(x => x.classList.toggle('on', x === b)); drawChart($('#trend'), r.daily);
      };
      campaignTable($('#camps'), r.campaigns, { onChange: () => { state.report = null; } });
    },
  };

  A.views.campaigns = {
    eyebrow: 'Campaigns', csv: exportCampaigns,
    async render(el) {
      el.innerHTML = `<section class="card" id="camps"><div class="card-head"><h2>Campaigns</h2></div><p style="padding:30px;text-align:center"><span class="spin"></span></p></section>
        `;
      let r;
      try { r = await loadReport(); } catch (e) { A.showError(e); return; }
      if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
      if (!$('#camps')) return;
      campaignTable($('#camps'), r.campaigns, { onChange: () => { state.report = null; } });
    },
  };
})();
