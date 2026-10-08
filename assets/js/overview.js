/* All accounts overview (from data synced into MySQL) + Sync */
(() => {
  const { $, $$, esc, icon, state, num, dec, derive, fmtId } = A;
  let chart = null;
  const fmtCur = (n, c) => { try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency: c || 'INR', maximumFractionDigits: Math.abs(n) >= 1000 ? 0 : 2 }).format(n || 0); } catch { return (c || '') + ' ' + dec(n); } };
  const ago = t => {
    if (!t) return '<span class="muted">Never</span>';
    const s = (Date.now() - new Date(t.replace(' ', 'T')).getTime()) / 1000;
    return s < 90 ? 'Just now' : s < 3600 ? Math.round(s / 60) + ' min ago' : s < 86400 ? Math.round(s / 3600) + ' hr ago' : Math.round(s / 86400) + ' d ago';
  };

  function draw(canvas, daily, cur) {
    if (!window.Chart) return;
    const rows = daily.filter(d => d.currency === cur);
    const g = canvas.getContext('2d').createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(37,99,235,.22)'); g.addColorStop(1, 'rgba(37,99,235,0)');
    chart?.destroy();
    chart = new Chart(canvas, {
      type: 'line',
      data: { labels: rows.map(d => new Date(d.date + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })),
              datasets: [{ data: rows.map(d => d.cost), borderColor: '#2563eb', backgroundColor: g, fill: true, tension: .35, pointRadius: rows.length > 45 ? 0 : 3, borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', displayColors: false, callbacks: { label: c => 'Spend ' + fmtCur(c.parsed.y, cur) } } },
        scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10, color: '#64748b' } }, y: { beginAtZero: true, grid: { color: '#eef2f7' }, border: { display: false }, ticks: { color: '#64748b', callback: v => fmtCur(v, cur) } } } },
    });
  }

  async function syncAll(btn, accounts, days, done) {
    const list = accounts.filter(a => a.status === 'ENABLED');
    if (!list.length) return A.toast('No active accounts to sync');
    const box = $('#syncBox');
    box.classList.remove('hidden');
    btn.disabled = true;
    let ok = 0, fail = 0, rows = 0;
    for (let i = 0; i < list.length; i++) {
      const a = list[i];
      box.innerHTML = `<span class="spin"></span> Syncing: <b>${esc(a.name)}</b> (${i + 1}/${list.length}) · ${ok} done${fail ? `, ${fail} failed` : ''}`;
      try {
        const r = await A.api('sync_account', {}, { conn: a.conn_id, cid: a.customer_id, days: days || '' });
        ok++; rows += r.rows;
      } catch (e) { fail++; }
    }
    box.innerHTML = `${icon('check', 'sm')} Sync complete: <b>${ok}</b> account(s), ${num(rows)} rows saved${fail ? ` · <span class="bad">${fail} failed</span> (see the error in the table)` : ''}.`;
    btn.disabled = false;
    done();
  }

  A.views.overview = {
    eyebrow: 'Overview', needsAccount: false,
    csv: () => {
      const d = A._ov; if (!d) return;
      A.csvDownload(`all_accounts_${state.from}_to_${state.to}.csv`,
        ['Customer ID', 'Account', 'Email', 'Via', 'Status', 'Currency', 'Impressions', 'Clicks', 'Cost', 'Conversions', 'Conv value', 'CPA', 'ROAS', 'Last sync'],
        d.accounts.map(derive).map(a => [a.customer_id, a.name, a.email, a.via, a.status, a.currency, a.impr, a.clicks, a.cost, a.conv, a.value, a.cpa.toFixed(2), a.roas.toFixed(2), a.last_sync || '']));
    },
    async render(el) {
      $('#accTitle').textContent = 'All accounts';
      $('#accSub').innerHTML = '<span class="small">All your connected Google Ads accounts</span>';
      el.innerHTML = `<div class="row" style="justify-content:space-between;margin-bottom:16px;gap:12px">
          
          <div class="row"><select id="sDays" style="width:auto"><option value="">Auto</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last 365 days</option></select>
          <button class="btn primary" id="syncBtn">${icon('refresh', 'sm')} Sync all accounts</button></div></div>
        <div id="syncBox" class="alert info hidden"></div>
        <section class="kpis" id="ovK"></section>
        <section class="card"><div class="card-head"><h2>${icon('barchart')} Total spend trend</h2><div class="seg" id="curSeg"></div></div><div class="chart-box"><canvas id="ovC"></canvas></div></section>
        <section class="card"><div class="card-head"><h2>Accounts <span class="pill" id="ovN">0</span></h2>
          <div class="tools"><div class="search-in">${icon('search', 'sm')}<input id="ovQ" placeholder="Account / email search…"></div>
          <select id="ovS"><option value="ENABLED">Active only</option><option value="">All</option></select>
          ${APP.canEdit ? `<a class="btn" href="#link-accounts">${icon('link', 'sm')} Link existing</a> <a class="btn primary" href="#new-accounts">${icon('plus', 'sm')} New accounts in manager</a>` : ''}</div></div>
          <div class="table-wrap" id="ovT"><p style="padding:30px;text-align:center"><span class="spin"></span></p></div></section>`;
      const load = async () => {
        let d;
        try { d = await A.api('overview', { from: state.from, to: state.to }); } catch (e) { A.showError(e); return; }
        if (!$('#ovT')) return;
        A._ov = d;
        const accs = d.accounts.map(derive);
        // Totals per currency (never mix e.g. INR and USD)
        const cnt = {};
        accs.filter(a => a.status === 'ENABLED').forEach(a => { const c = a.currency || 'INR'; cnt[c] = (cnt[c] || 0) + 1; });
        const curs = Object.keys(cnt).sort((x, y) => cnt[y] - cnt[x] || (x === 'INR' ? -1 : y === 'INR' ? 1 : 0));
        if (!curs.length) curs.push('INR');
        const main = curs[0];
        const tot = c => A.sum(accs.filter(a => (a.currency || 'INR') === c));
        const t = tot(main);
        const active = accs.filter(a => a.status === 'ENABLED').length;
        $('#ovK').innerHTML = [
          ['Total spend' + (curs.length > 1 ? ` (${main})` : ''), fmtCur(t.cost, main), 't-blue', main === 'INR' ? 'rupee' : 'dollar'],
          ['Clicks', num(t.clicks), 't-green', 'mouse'],
          ['Conversions', dec(t.conv), 't-pink', 'funnel'],
          ['ROAS', dec(t.roas) + 'x', 't-violet', 'trend'],
        ].map(([l, v, tint, ic]) => `<div class="kpi ${tint}"><div class="ic">${icon(ic)}</div><div class="body"><div class="lbl">${l}</div><div class="val">${v}</div>
          <span class="delta flat">${l.startsWith('Total') ? `${active} active account(s)` : l === 'ROAS' ? `CPA ${t.conv ? fmtCur(t.cpa, main) : '—'}` : l === 'Clicks' ? `CTR ${dec(t.ctr)}%` : `Conv. rate ${dec(t.clicks ? t.conv / t.clicks * 100 : 0)}%`}</span></div></div>`).join('')
          + (curs.length > 1 ? curs.slice(1).map(c => { const x = tot(c); return `<div class="kpi t-orange"><div class="ic">${icon('dollar')}</div><div class="body"><div class="lbl">Spend (${c})</div><div class="val">${fmtCur(x.cost, c)}</div><span class="delta flat">ROAS ${dec(x.roas)}x</span></div></div>`; }).join('') : '');
        $('#curSeg').innerHTML = curs.length > 1 ? curs.map((c, i) => `<button data-c="${c}" class="${i ? '' : 'on'}">${c}</button>`).join('') : '';
        draw($('#ovC'), d.daily, main);
        $('#curSeg').onclick = e => { const b = e.target.closest('button'); if (!b) return; $$('#curSeg button').forEach(x => x.classList.toggle('on', x === b)); draw($('#ovC'), d.daily, b.dataset.c); };

        const t2 = new A.Table($('#ovT'), [
          { k: 'name', label: 'Account', cls: 'l', html: a => `<a class="nm" data-open="${esc(a.conn_id)}:${esc(a.customer_id)}">${esc(a.name)}</a>${a.shared_by ? ` <span class="pill" title="Shared by ${esc(a.shared_by)} (${esc(a.access)})">Shared</span>` : ''}<span class="sub">${fmtId(a.customer_id)} · ${esc(a.email)}${a.via ? ' · via ' + esc(a.via) : ''}${a.shared_by ? ' · shared by ' + esc(a.shared_by) : ''}</span>` },
          { k: 'status', label: 'Status', cls: 'l', html: a => A.statusPill(a.status === 'NOT_ENABLED' ? 'PAUSED' : a.status).replace('Paused', a.status === 'NOT_ENABLED' ? 'Inactive' : 'Paused') },
          { k: 'active_campaigns', label: 'Campaigns', html: a => num(a.active_campaigns) },
          { k: 'impr', label: 'Impr.', html: a => num(a.impr) },
          { k: 'clicks', label: 'Clicks', html: a => num(a.clicks) },
          { k: 'ctr', label: 'CTR', html: a => dec(a.ctr) + '%' },
          { k: 'cost', label: 'Cost', html: a => `<b>${fmtCur(a.cost, a.currency)}</b>` },
          { k: 'conv', label: 'Conv.', html: a => dec(a.conv) },
          { k: 'cpa', label: 'CPA', html: a => a.conv ? fmtCur(a.cpa, a.currency) : '—' },
          { k: 'value', label: 'Conv. value', html: a => fmtCur(a.value, a.currency) },
          { k: 'roas', label: 'ROAS', html: a => A.roasCell(a) },
          { k: 'last_sync', label: 'Last sync', html: a => `${ago(a.last_sync)}${a.sync_error ? `<span class="sub bad" title="${esc(a.sync_error)}">Error: ${esc(a.sync_error.slice(0, 40))}…</span>` : ''}` },
          { k: 'x', label: '', sort: false, html: a => a.status === 'ENABLED' ? `<button class="btn sm" data-sync="${esc(a.conn_id)}:${esc(a.customer_id)}" title="Sync this account only">${icon('refresh', 'sm')}</button>` : '' },
        ], {
          rows: accs, sortKey: 'cost', empty: d.demo ? '—' : 'No accounts. Connect a Google account, then click Sync.',
          filter: a => (a.name + a.email + a.customer_id + (a.via || '')).toLowerCase().includes($('#ovQ').value.toLowerCase()) && (!$('#ovS').value || a.status === $('#ovS').value),
          after: rs => { $('#ovN').textContent = rs.length; },
        });
        t2.render();
        $('#ovQ').oninput = () => t2.render();
        $('#ovS').onchange = () => t2.render();
        $('#syncBtn').onclick = e => syncAll(e.currentTarget, d.accounts, $('#sDays').value, load);
        if (!d.demo && !accs.some(a => a.last_sync) && accs.length) {
          const b = $('#syncBox'); b.classList.remove('hidden');
          b.innerHTML = `No data synced yet. Click <b>"Sync all accounts"</b> (the first run ${accs.length > 10 ? 'takes a little while' : 'takes a few seconds'}), or wait for the hourly cron.`;
        }
        return accs;
      };
      await load();
      el.onclick = async e => {
        const o = e.target.closest('[data-open]');
        if (o) {
          const [conn, id] = o.dataset.open.split(':');
          const acc = state.accounts.find(x => x.conn === conn && x.id === id);
          if (acc) { A.setAccount(acc, false); location.hash = '#dashboard'; } else A.toast('Account not in the list - refresh the account picker');
        }
        const s = e.target.closest('[data-sync]');
        if (s) {
          const [conn, cid] = s.dataset.sync.split(':');
          A.busy(s, async () => {
            try { const r = await A.api('sync_account', {}, { conn, cid, days: $('#sDays').value || '' }); A.toast(`${r.rows} rows synced`); await load(); }
            catch (err) { A.showError(err); await load(); }
          });
        }
      };
    },
  };
})();
