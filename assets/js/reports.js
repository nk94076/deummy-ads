/* Reports (device / day / hour / network) + Analytics (insights) */
(() => {
  const { $, $$, esc, icon, state, num, dec, money, derive, label } = A;
  const charts = {};
  let metric = 'cost';
  const DOW = { MONDAY: 'Mon', TUESDAY: 'Tue', WEDNESDAY: 'Wed', THURSDAY: 'Thu', FRIDAY: 'Fri', SATURDAY: 'Sat', SUNDAY: 'Sun' };
  const REPS = [['device', 'Device'], ['dow', 'Day of week'], ['hour', 'Hour of day'], ['network', 'Network']];
  const fmtM = (m, v) => ['cost', 'value', 'cpa'].includes(m) ? money(v) : m === 'roas' ? dec(v) + 'x' : m === 'conv' ? dec(v) : num(v);
  const data = {};

  function bar(id, labels, values, horizontal = false) {
    const cv = $('#' + id); if (!cv || !window.Chart) return;
    charts[id]?.destroy();
    charts[id] = new Chart(cv, {
      type: 'bar',
      data: { labels, datasets: [{ data: values, backgroundColor: '#3b82f6', hoverBackgroundColor: '#1d4ed8', borderRadius: 4, borderSkipped: 'start', maxBarThickness: 34 }] },
      options: {
        indexAxis: horizontal ? 'y' : 'x', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', displayColors: false, callbacks: { label: c => fmtM(metric, horizontal ? c.parsed.x : c.parsed.y) } } },
        scales: (() => {
          const valTicks = { color: '#64748b', callback: v => fmtM(metric, v) };
          const catTicks = { color: '#64748b', autoSkip: true, callback: function (v) { const l = String(this.getLabelForValue(v)); return l.length > 26 ? l.slice(0, 25) + '…' : l; } };
          return horizontal
            ? { x: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: valTicks }, y: { grid: { display: false }, border: { display: false }, ticks: catTicks } }
            : { x: { grid: { display: false }, ticks: catTicks }, y: { beginAtZero: true, grid: { color: '#eef2f7' }, border: { display: false }, ticks: valTicks } };
        })(),
      },
    });
  }

  function drawRep(type) {
    if (!$('#tb_' + type)) return;
    const rows = (data[type] || []).map(derive);
    const order = type === 'hour' ? rows.sort((a, b) => +a.label - +b.label) : type === 'dow' ? rows.sort((a, b) => Object.keys(DOW).indexOf(a.label) - Object.keys(DOW).indexOf(b.label)) : rows.sort((a, b) => b.cost - a.cost);
    const lbl = r => type === 'dow' ? DOW[r.label] || r.label : type === 'hour' ? r.label + ':00' : label(r.label);
    bar('ch_' + type, order.map(lbl), order.map(r => r[metric]));
    $('#tb_' + type).innerHTML = `<table class="data"><thead><tr><th class="l nosort">${REPS.find(x => x[0] === type)[1]}</th><th class="nosort">Clicks</th><th class="nosort">Cost</th><th class="nosort">Conv.</th><th class="nosort">CPA</th><th class="nosort">ROAS</th></tr></thead><tbody>
      ${order.map(r => `<tr><td class="l">${esc(lbl(r))}</td><td>${num(r.clicks)}</td><td>${money(r.cost)}</td><td>${dec(r.conv)}</td><td>${r.conv ? money(r.cpa) : '—'}</td><td>${A.roasCell(r)}</td></tr>`).join('') || '<tr><td class="empty" colspan="6">No data</td></tr>'}</tbody></table>`;
  }

  A.views.reports = {
    eyebrow: 'Reports',
    csv: () => {
      const rows = [];
      REPS.forEach(([t, n]) => (data[t] || []).map(derive).forEach(r => rows.push([n, r.label, r.impr, r.clicks, r.cost, r.conv, r.value, r.cpa.toFixed(2), r.roas.toFixed(2)])));
      A.csvDownload(`reports_${state.acc.id}_${state.from}_to_${state.to}.csv`, ['Report', 'Segment', 'Impressions', 'Clicks', 'Cost', 'Conversions', 'Conv value', 'CPA', 'ROAS'], rows);
    },
    async render(el) {
      el.innerHTML = `<div class="row" style="justify-content:space-between;margin-bottom:16px"><p class="muted" style="margin:0"></p>
        <div class="seg" id="rSeg">${[['cost', 'Cost'], ['clicks', 'Clicks'], ['conv', 'Conversions'], ['value', 'Conv. value'], ['roas', 'ROAS']].map(([k, l]) => `<button data-m="${k}" class="${metric === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
        <div class="rep-grid">${REPS.map(([k, l]) => `<section class="card"><div class="card-head"><h2>${icon('bars')} ${l}</h2></div>
          <div class="bar-box"><canvas id="ch_${k}"></canvas></div><div class="table-wrap" id="tb_${k}" style="margin-top:10px;max-height:260px;overflow:auto"><p style="padding:20px;text-align:center"><span class="spin"></span></p></div></section>`).join('')}</div>`;
      $('#rSeg').onclick = e => { const b = e.target.closest('button'); if (!b) return; metric = b.dataset.m; $$('#rSeg button').forEach(x => x.classList.toggle('on', x === b)); REPS.forEach(([t]) => drawRep(t)); };
      await Promise.all(REPS.map(async ([t]) => {
        try {
          const r = await A.get('breakdown', { type: t });
          if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
          data[t] = r.rows; if (state.view === 'reports') drawRep(t);
        } catch (e) { A.showError(e); }
      }));
    },
  };

  // ================= Analytics / insights =================
  function insights(r) {
    const cs = r.campaigns.map(derive), t = derive(r.totals), days = r.days || 1, out = [];
    const waste = cs.filter(c => c.cost > 0 && c.conv === 0).sort((a, b) => b.cost - a.cost);
    if (waste.length) {
      const w = waste.reduce((s, c) => s + c.cost, 0);
      out.push(['bad', 'alert', `${money(w)} spent with no conversions`, `${waste.length} campaign(s) have spend but 0 conversions: ${waste.slice(0, 3).map(c => `<a href="#campaign/${c.id}/terms">${esc(c.name)}</a>`).join(', ')}. Review search terms and add negative keywords, or pause.`]);
    }
    const withConv = cs.filter(c => c.conv > 0 && c.value > 0).sort((a, b) => b.roas - a.roas);
    if (withConv.length) {
      const b = withConv[0];
      out.push(['good', 'trend', `Best ROAS: ${esc(b.name)} (${dec(b.roas)}x)`, `Highest return in this account. Budget is ${money(b.budget)}/day${b.cost / days >= b.budget * 0.85 ? ' and spend is close to it. Consider <b>raising the budget</b>.' : '. Raise the budget gradually to scale.'} <a href="#campaign/${b.id}/settings">Open settings</a>`]);
    }
    const lim = cs.filter(c => c.budget && c.cost / days >= c.budget * 0.9 && c.status === 'ENABLED' && c.roas >= 1.5 && c !== withConv[0]);
    lim.slice(0, 2).forEach(c => out.push(['info', 'rupee', `${esc(c.name)} looks limited by budget`, `Spending ~${money(c.cost / days)}/day against a ${money(c.budget)} budget at ${dec(c.roas)}x ROAS. A higher budget may bring more conversions. <a href="#campaign/${c.id}/settings">Edit budget</a>`]));
    if (t.conv > 0) {
      cs.filter(c => c.conv > 0 && c.cpa > t.cpa * 1.5 && c.cost > t.cost * 0.05).slice(0, 3).forEach(c =>
        out.push(['bad', 'target', `${esc(c.name)} CPA is ${dec((c.cpa / t.cpa - 1) * 100, 0)}% above account average`, `CPA ${money(c.cpa)} vs average ${money(t.cpa)}. Review keywords and search terms, or set a target CPA. <a href="#campaign/${c.id}/keywords">Open keywords</a>`]));
    }
    cs.filter(c => c.type === 'SEARCH' && c.impr > 1000 && c.ctr < 2).slice(0, 2).forEach(c =>
      out.push(['info', 'mouse', `${esc(c.name)} has a low CTR (${dec(c.ctr)}%)`, `3%+ is a healthy CTR for Search. Use the keyword in ad copy and add irrelevant search terms as negatives. <a href="#campaign/${c.id}/ads">View ads</a>`]));
    if (!out.length) out.push(['good', 'check', 'No issues found', 'Nothing stands out in this date range. Try the last 30 days for more data.']);
    return out;
  }

  A.views.analytics = {
    eyebrow: 'Analytics',
    async render(el) {
      el.innerHTML = `<section class="card"><div class="card-head"><h2>${icon('bulb')} Smart insights</h2><span class="muted small">Selected date range</span></div><div id="ins"><p style="padding:24px;text-align:center"><span class="spin"></span></p></div></section>
        <div class="rep-grid"><section class="card"><div class="card-head"><h2>${icon('bars')} Spend by campaign</h2></div><div class="bar-box" style="height:320px"><canvas id="ch_camp"></canvas></div></section>
        <section class="card"><div class="card-head"><h2>${icon('pie')} Share of spend vs conversions</h2></div><div class="table-wrap" id="share"></div></section></div>`;
      let r;
      try { r = await A.loadReport(); } catch (e) { A.showError(e); return; }
      if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
      if (!$('#ins')) return;
      $('#ins').innerHTML = insights(r).map(([k, ic, t, d]) => `<div class="insight"><div class="ic ${k}">${icon(ic)}</div><div><b>${t}</b><div class="muted">${d}</div></div></div>`).join('');
      const cs = r.campaigns.map(derive).filter(c => c.cost > 0).sort((a, b) => b.cost - a.cost).slice(0, 10);
      metric = 'cost';
      bar('ch_camp', cs.map(c => c.name), cs.map(c => c.cost), true);
      const t = derive(r.totals);
      $('#share').innerHTML = `<table class="data"><thead><tr><th class="l nosort">Campaign</th><th class="nosort">% Spend</th><th class="nosort">% Conv.</th><th class="nosort">% Value</th></tr></thead><tbody>
        ${r.campaigns.map(derive).filter(c => c.cost > 0).sort((a, b) => b.cost - a.cost).map(c => {
          const s = t.cost ? c.cost / t.cost * 100 : 0, cv = t.conv ? c.conv / t.conv * 100 : 0, v = t.value ? c.value / t.value * 100 : 0;
          return `<tr><td class="l"><a class="nm" href="#campaign/${c.id}">${esc(c.name)}</a></td><td>${dec(s, 1)}%</td><td class="${cv >= s ? 'good' : 'bad'}">${dec(cv, 1)}%</td><td class="${v >= s ? 'good' : 'bad'}">${dec(v, 1)}%</td></tr>`;
        }).join('') || '<tr><td class="empty" colspan="4">No spend in this range</td></tr>'}</tbody></table>
        <p class="muted small" style="padding:0 24px 16px">Green = conversion share above spend share. Red = spend share above conversion share.</p>`;
    },
  };
})();
