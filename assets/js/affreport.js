/* Affiliate Report: one combined daily report of real affiliate revenue (Impact + AWIN).
   Shows OLD and NEW data together — matched (tied to a campaign) and unmatched — with
   daily rollup, per-currency totals, and a full conversion detail (sub-id, click id, txn id). */
(() => {
  const { $, $$, esc, icon } = A;

  const NET_NAMES = { impact: 'Impact.com', awin: 'AWIN' };
  const CUR = c => ({ USD: '$', INR: '₹', GBP: '£', EUR: '€', AED: 'AED ', AUD: 'A$', CAD: 'C$' }[c] || (c ? c + ' ' : ''));
  const amt = (v, c) => CUR(c) + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const RANGES = [[30, '30 days'], [90, '90 days'], [180, '180 days'], [365, '1 year'], [3650, 'All time']];

  let days = 90;                 // selected window (view-local, independent of the global date bar)
  let last = null;               // last report payload (for CSV export)

  const ymd = d => d.toISOString().slice(0, 10);
  function window_() {
    const to = new Date();
    const from = new Date(); from.setDate(from.getDate() - (days - 1));
    return { from: ymd(from), to: ymd(to) };
  }

  const idCell = v => v ? `<span class="mono small" title="${esc(v)}">${esc(v.length > 18 ? v.slice(0, 18) + '…' : v)}</span>` : '<span class="muted">—</span>';
  const stCls = s => s === 'APPROVED' ? 'st-ENABLED' : (s === 'REVERSED' ? 'st-PAUSED' : '');

  A.views.affreport = {
    eyebrow: 'Affiliate Report', needsAccount: false, dates: false,

    async render(el) {
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      const { from, to } = window_();
      let rep;
      try { rep = await A.api('affiliate_report', { from, to }); }
      catch (e) { A.showError(e); el.innerHTML = ''; return; }
      last = rep;

      const rangeBtns = RANGES.map(([d, lbl]) =>
        `<button class="btn sm ${d === days ? 'primary' : ''}" data-days="${d}">${lbl}</button>`).join('');

      // ---- per-currency totals ----
      const totalCards = rep.totals.length ? rep.totals.map(t => `
        <div class="kpi">
          <div class="body">
            <div class="lbl">${esc(CUR(t.currency).trim() || 'Commission')} · live commission</div>
            <div class="val">${amt(t.commission, t.currency)}</div>
            <div class="muted small">Sale ${amt(t.sale, t.currency)} · ${t.count} conv · <span style="color:#16a34a;font-weight:600">${t.matched} matched</span> · ${t.unmatched} unmatched</div>
            <div class="muted small" style="margin-top:2px">Approved ${amt(t.approved, t.currency)} · Pending ${amt(t.pending, t.currency)} · Reversed ${amt(t.reversed, t.currency)}</div>
          </div>
        </div>`).join('')
        : '<div class="muted" style="padding:16px">No affiliate conversions in this window. Connect Impact/AWIN and run a sync.</div>';

      // ---- daily rollup ----
      const dailyRows = rep.daily.map(d => `<tr>
        <td class="l">${esc(d.date)}</td>
        <td class="l">${esc(CUR(d.currency).trim() || '—')}</td>
        <td>${d.count}</td>
        <td>${amt(d.sale, d.currency)}</td>
        <td><b>${amt(d.commission, d.currency)}</b></td>
        <td>${amt(d.approved, d.currency)}</td>
        <td>${amt(d.pending, d.currency)}</td>
        <td>${amt(d.reversed, d.currency)}</td>
        <td>${d.matched}</td>
        <td>${d.unmatched ? `<span class="badge">${d.unmatched}</span>` : '0'}</td>
      </tr>`).join('');

      // ---- full detail ----
      const detailRows = rep.rows.map(x => `<tr>
        <td class="l">${esc(x.date)}</td>
        <td class="l">${esc(NET_NAMES[x.network] || x.network)}</td>
        <td class="l">${x.campaign_id ? esc(A.fmtId(x.campaign_id)) : '<span class="badge">unmatched</span>'}</td>
        <td class="l small">${x.sub_id ? esc(x.sub_id) : '<span class="muted">—</span>'}</td>
        <td class="l">${idCell(x.click_id)}</td>
        <td class="l">${idCell(x.txn_id)}</td>
        <td>${amt(x.sale, x.currency)}</td>
        <td><b>${amt(x.commission, x.currency)}</b></td>
        <td class="l"><span class="status ${stCls(x.status)}">${esc(x.status)}</span></td>
      </tr>`).join('');

      el.innerHTML = `
        <section class="card">
          <div class="card-head">
            <h2>${icon('bars')} Affiliate report</h2>
            <div class="tools">${rangeBtns}
              <button class="btn sm" id="ar_sync">${icon('refresh', 'sm')} Sync now</button>
              <button class="btn sm" id="ar_csv">${icon('dl', 'sm')} CSV</button></div>
          </div>
          <div class="card-body">
            <p class="muted small" style="margin:0 0 12px">${esc(rep.from)} → ${esc(rep.to)} · old &amp; new data, matched &amp; unmatched. Unmatched = older links that didn't carry the campaign id; add <code>subId1={campaignid}</code> so new ones match automatically.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">${totalCards}</div>
          </div>
        </section>

        <section class="card"><div class="card-head"><h2>${icon('line')} Daily</h2></div>
          <div class="table-wrap"><table class="data">
            <thead><tr><th class="l">Date</th><th class="l">Cur</th><th>Conv</th><th>Sale</th><th>Commission</th><th>Approved</th><th>Pending</th><th>Reversed</th><th>Matched</th><th>Unmatched</th></tr></thead>
            <tbody>${dailyRows || '<tr><td colspan="10" class="muted" style="padding:20px;text-align:center">No data</td></tr>'}</tbody>
          </table></div>
        </section>

        <section class="card"><div class="card-head"><h2>${icon('target')} All conversions <span class="pill">${rep.rows.length}</span></h2></div>
          <div class="table-wrap"><table class="data">
            <thead><tr><th class="l">Date</th><th class="l">Network</th><th class="l">Campaign</th><th class="l">Sub-id</th><th class="l">Click ID</th><th class="l">Txn ID</th><th>Sale</th><th>Commission</th><th class="l">Status</th></tr></thead>
            <tbody>${detailRows || '<tr><td colspan="9" class="muted" style="padding:20px;text-align:center">No conversions yet</td></tr>'}</tbody>
          </table></div>
        </section>`;

      $$('[data-days]').forEach(b => b.onclick = () => { days = +b.dataset.days; A.views.affreport.render(el); });
      $('#ar_sync') && ($('#ar_sync').onclick = e => A.busy(e.currentTarget, async () => {
        try { const r = await A.api('network_sync', {}, {}); A.toast(`Synced ${r.rows} conversions`); A.views.affreport.render(el); }
        catch (err) { A.showError(err); }
      }));
      $('#ar_csv') && ($('#ar_csv').onclick = () => A.views.affreport.csv());
    },

    csv() {
      if (!last || !last.rows.length) { A.toast('Nothing to export'); return; }
      const head = ['Date', 'Network', 'Campaign ID', 'Sub-id', 'Click ID', 'Txn ID', 'Sale', 'Commission', 'Currency', 'Status'];
      const rows = last.rows.map(x => [x.date, NET_NAMES[x.network] || x.network, x.campaign_id || 'unmatched',
        x.sub_id, x.click_id, x.txn_id, x.sale, x.commission, x.currency, x.status]);
      A.csvDownload(`affiliate-report_${last.from}_${last.to}.csv`, head, rows);
    },
  };
})();
