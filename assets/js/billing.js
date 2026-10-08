/* Billing: payments profile + spend summary (selected dates and full history), monthly statements, spend by campaign */
(() => {
  const { $, esc, icon, state, num, dec, money, fmtId, statusPill } = A;
  const loading = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
  const monthName = m => new Date(m + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
  const dayName = d => new Date(d + 'T00:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  let last = null;
  let chart = null;

  A.views.billing = {
    eyebrow: 'Billing',
    async render(el) {
      el.innerHTML = loading;
      let d;
      try { d = await A.get('billing'); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (d.blocked) { el.innerHTML = A.blockedHtml(d.blocked); return; }
      last = d;
      const p = d.profile || {};
      const r = d.range;
      const life = d.lifetime;
      const statements = life ? life.months : d.months;
      const rate = d.tax_rate;
      const rowsDl = [
        ['Payments profile', p.name],
        ['Payments account ID', p.payments_account_id],
        ['Google Ads customer ID', fmtId(state.acc?.id)],
        ['Payment setting', p.payment_setting],
        ['Payment method', p.payment_method],
        ['GSTIN', p.gstin],
        ['Billing address', p.address],
        ['Currency', 'Indian Rupee (INR)'],
      ].filter(x => x[1]);
      const fmtRange = `${dayName(d.from)} – ${dayName(d.to)}`;

      el.innerHTML = `
        <div class="bill-head">
          <section class="card"><div class="card-head"><h2>${icon('case')} Payments profile</h2></div>
            <div class="card-body"><div class="bill-prof"><div class="bill-logo">${esc((p.name || '?')[0])}</div>
              <div class="grow"><div style="font-size:17px;font-weight:700">${esc(p.name || '')}</div>
                <div class="muted small">Business · ${esc(state.acc?.name || '')}</div>
                <dl class="bill-dl">${rowsDl.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl></div></div></div>
          </section>
          <section class="card"><div class="card-head"><h2>${icon('rupee')} Spend summary</h2><span class="muted small">${esc(fmtRange)}</span></div>
            <div class="card-body">
              <div class="muted small">Ad spend in selected dates</div>
              <div class="bill-big">${money(r.cost, true)}</div>
              <dl class="bill-dl">
                <dt>GST (${dec(rate, 0)}%)</dt><dd>${money(r.tax, true)}</dd>
                <dt>Total incl. GST</dt><dd>${money(r.total, true)}</dd>
                <dt>Clicks · Conversions</dt><dd>${num(r.clicks)} · ${dec(r.conv)}</dd>
              </dl>
              ${life ? `<div class="sep" style="margin:16px 0;border-top:1px solid var(--line-2)"></div>
                <div class="muted small">Total spend to date (${esc(dayName(life.from))} – ${esc(dayName(life.to))})</div>
                <div class="bill-big" style="font-size:24px">${money(life.cost, true)}</div>
                <div class="muted small">${money(life.total, true)} incl. GST · ${num(life.clicks)} clicks</div>` : ''}
            </div>
          </section>
        </div>

        <section class="card"><div class="card-head"><h2>${icon('bars')} Daily spend <span class="pill">${esc(fmtRange)}</span></h2></div>
          <div class="card-body"><div style="height:260px"><canvas id="billChart"></canvas></div></div></section>

        <section class="card"><div class="card-head"><h2>${icon('cal')} Monthly statements <span class="pill">${statements.length}</span></h2>
          <span class="muted small">${life ? 'Full account history' : 'Selected dates'}</span></div>
          <div class="table-wrap" id="monT"></div></section>

        <section class="card"><div class="card-head"><h2>${icon('send')} Spend by campaign <span class="pill">${d.campaigns.length}</span></h2>
          <span class="muted small">${esc(fmtRange)}</span></div>
          <div class="table-wrap" id="campT"></div></section>`;

      new A.Table($('#monT'), [
        { k: 'month', label: 'Statement', cls: 'l', html: m => `<b>${esc(monthName(m.month))}</b><span class="sub">${esc(p.name || '')}</span>` },
        { k: 'campaigns', label: 'Campaigns', html: m => num(m.campaigns) },
        { k: 'clicks', label: 'Clicks', html: m => num(m.clicks) },
        { k: 'conv', label: 'Conv.', html: m => dec(m.conv) },
        { k: 'cost', label: 'Ad spend', html: m => `<b>${money(m.cost, true)}</b>` },
        { k: 'tax', label: `GST ${dec(rate, 0)}%`, html: m => money(m.tax, true) },
        { k: 'total', label: 'Total', html: m => `<b>${money(m.total, true)}</b>` },
      ], { rows: statements, sortKey: 'month', dir: 'asc', empty: 'No spend in this period',
        footer: rows => { const s = k => rows.reduce((a, m) => a + (+m[k] || 0), 0);
          return `<tr><td class="l">Total</td><td></td><td>${num(s('clicks'))}</td><td>${dec(s('conv'))}</td><td>${money(s('cost'), true)}</td><td>${money(s('tax'), true)}</td><td>${money(s('total'), true)}</td></tr>`; } }).render();

      const rc = r.cost || 1;
      new A.Table($('#campT'), [
        { k: 'name', label: 'Campaign', cls: 'l', html: c => `<a href="#campaign/${esc(c.id)}"><b>${esc(c.name)}</b></a>` },
        { k: 'status', label: 'Status', cls: 'l', html: c => statusPill(c.status) },
        { k: 'clicks', label: 'Clicks', html: c => num(c.clicks) },
        { k: 'conv', label: 'Conv.', html: c => dec(c.conv) },
        { k: 'cost', label: 'Ad spend', html: c => `<b>${money(c.cost, true)}</b>` },
        { k: 'share', label: 'Share of spend', sort: false, html: c => `<div style="min-width:120px">${dec(c.cost / rc * 100, 1)}%<div class="bill-bar"><span style="width:${Math.min(100, c.cost / rc * 100).toFixed(1)}%"></span></div></div>` },
      ], { rows: d.campaigns, sortKey: 'cost', empty: 'No spend in the selected dates' }).render();

      drawChart($('#billChart'), d.daily, d.from, d.to);
    },
    csv() {
      if (!last) return;
      const rows = (last.lifetime ? last.lifetime.months : last.months);
      A.csvDownload(`billing-${state.acc?.id || ''}.csv`, ['Month', 'Campaigns', 'Clicks', 'Conversions', 'Ad spend (INR)', `GST ${last.tax_rate}%`, 'Total (INR)'],
        rows.map(m => [m.month, m.campaigns, m.clicks, m.conv, m.cost.toFixed(2), m.tax.toFixed(2), m.total.toFixed(2)]));
    },
  };

  function drawChart(canvas, daily, from, to) {
    if (!window.Chart || !canvas) return;
    const by = Object.fromEntries(daily.map(x => [x.date, x.cost]));
    const labels = [], data = [];
    for (let t = new Date(from + 'T00:00:00'); t <= new Date(to + 'T00:00:00'); t.setDate(t.getDate() + 1)) {
      const k = new Date(t.getTime() - t.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
      labels.push(t.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }));
      data.push(by[k] || 0);
    }
    if (chart) chart.destroy();
    chart = new Chart(canvas, {
      type: 'bar',
      data: { labels, datasets: [{ data, backgroundColor: '#2563eb', borderRadius: 4, maxBarThickness: 28 }] },
      options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + money(c.parsed.y, true) } } },
        scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: { beginAtZero: true, ticks: { callback: v => money(v) } } } },
    });
  }
})();
