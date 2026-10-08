/* AI Optimizer: optimize Google Ads on REAL affiliate revenue (Impact + AWIN). */
(() => {
  const { $, $$, esc, icon, money, num, state } = A;

  const NET_NAMES = { impact: 'Impact.com', awin: 'AWIN' };

  function connectModal(net, fields, existing, done) {
    const fieldRows = Object.entries(fields).map(([k, label]) =>
      `<label class="f">${esc(label)}</label><input data-cf="${esc(k)}" autocomplete="off" placeholder="${esc(label)}">`).join('');
    const m = A.modal({
      title: `Connect ${NET_NAMES[net] || net}`,
      body: `<p class="muted small" style="margin:0 0 12px">Enter your ${esc(NET_NAMES[net])} API credentials to pull real conversions, or use sample data to try it first.</p>
        <label class="f">Label <span class="muted">(your reference)</span></label><input id="cn_label" value="${esc(existing?.label || NET_NAMES[net])}">
        <div id="cn_fields">${fieldRows}</div>
        <label class="row f" style="gap:10px;margin-top:10px"><span class="switch"><input type="checkbox" id="cn_demo"><span></span></span> Use sample data (no keys needed — try it out)</label>
        <div class="alert err" id="cn_err" style="display:none;margin-top:12px"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="cn_save">Connect</button>`,
    });
    const demo = m.$('#cn_demo');
    const fieldBox = m.$('#cn_fields');
    demo.onchange = () => { fieldBox.style.opacity = demo.checked ? '.4' : '1'; fieldBox.querySelectorAll('input').forEach(i => i.disabled = demo.checked); };
    m.$('#cn_save').onclick = e => A.busy(e.currentTarget, async () => {
      const creds = {};
      if (demo.checked) creds.demo = true;
      else m.el.querySelectorAll('[data-cf]').forEach(i => creds[i.dataset.cf] = i.value.trim());
      try {
        await A.api('network_save', {}, { network: net, label: m.$('#cn_label').value, creds, id: existing?.id });
        m.close(); A.toast(`${NET_NAMES[net]} connected`); done();
      } catch (err) { const b = m.$('#cn_err'); b.style.display = 'block'; b.textContent = err.error || 'Error'; }
    });
  }

  function trackingHelper() {
    A.modal({
      title: 'Affiliate tracking setup (sub-id)',
      wide: true,
      body: `<p class="muted small" style="margin:0 0 12px">For the optimizer to match affiliate commission back to the right campaign, the network's <b>sub-id</b> must carry the Google <b>campaign id</b>. Affiliate links redirect to another domain, so put the affiliate link in the <b>Tracking template</b> (not the Final URL — that triggers a "destination mismatch"). Google fills <code>{campaignid}</code> and <code>{lpurl}</code> automatically.</p>
        <div class="alert info" style="margin:0 0 14px"><b>Final URL</b> stays the real landing page (where users end up), e.g. <code>https://www.trivago.co.uk/...</code> — this avoids the destination-mismatch error.</div>
        <label class="f">Impact.com — Tracking template <span class="muted">(Campaign → Settings → Campaign URL options)</span></label>
        <pre class="example" style="user-select:all;white-space:pre-wrap;word-break:break-all">https://YOURLINK.sjv.io/c/PID/ADID/PROGID?subId1={campaignid}</pre>
        <div class="alert warn" style="margin:6px 0 14px"><b>Don't add <code>&url={lpurl}</code> to an Impact /c/ link.</b> The link already redirects to the offer page, and with <b>parallel tracking</b> Google puts a <code>google.com/asnc/…</code> wrapper into that param, which Impact can't load ("tracking call unsuccessful"). If Google asks for <code>{lpurl}</code> on save, append a param Impact ignores — <code>&lp={lpurl}</code> — or turn off parallel tracking (Account settings → Tracking).</div>
        <label class="f">AWIN — Tracking template</label>
        <pre class="example" style="user-select:all;white-space:pre-wrap;word-break:break-all">https://www.awin1.com/cread.php?awinmid=MID&awinaffid=AID&clickref={campaignid}&ued={lpurl}</pre>
        <div class="alert info" style="margin-top:12px"><b>Final URL</b> = the real landing page (e.g. the offer page). <code>{campaignid}</code> → the network's sub-id, so every conversion ties back to its campaign. Run Google's <b>Test</b> and expect a green tick. <b>Per-click / keyword level later:</b> add <code>subId2={gclid}</code> (Impact).</div>`,
      foot: `<button class="btn primary" data-close>Got it</button>`,
    });
  }

  const sevClass = s => s === 'high' ? 'bad' : (s === 'medium' ? 'warn' : '');
  const sevLabel = s => s === 'high' ? 'High' : (s === 'medium' ? 'Medium' : 'Low');

  A.views.optimizer = {
    eyebrow: 'AI Optimizer', needsAccount: true, dates: true,
    async render(el) {
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      let nets, rep, aff;
      try { [nets, rep, aff] = await Promise.all([A.api('networks'), A.get('optimizer'), A.api('affiliate_conversions')]); }
      catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (rep.blocked) { el.innerHTML = A.blockedHtml(rep.blocked); return; }
      const cur = c => ({ USD: '$', INR: '₹', GBP: '£', EUR: '€', AED: 'AED ', AUD: 'A$', CAD: 'C$' }[c] || (c ? c + ' ' : ''));
      const amt = (v, c) => cur(c) + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const netCards = ['impact', 'awin'].map(n => {
        const acct = nets.networks.find(x => x.network === n);
        return `<div class="list-item"><div class="avatar" style="background:var(--accent-2);color:var(--accent)">${esc((NET_NAMES[n][0]))}</div>
          <div class="grow"><div class="t">${esc(NET_NAMES[n])}${acct ? (acct.demo && A.state.demoLabels ? ' <span class="badge">demo</span>' : '') : ''}</div>
            <div class="muted small">${acct ? (acct.status === 'error' ? `<span class="bad">${esc(acct.last_error || 'error')}</span>` : `Connected${acct.last_sync ? ' · synced ' + esc(acct.last_sync) : ''}`) : 'Not connected'}</div></div>
          ${acct
            ? `<button class="btn sm" data-net-edit="${n}">Edit</button> <button class="btn sm danger" data-net-rm="${acct.id}">Remove</button>`
            : `<button class="btn sm primary" data-net-add="${n}">Connect</button>`}</div>`;
      }).join('');

      const t = rep.totals;
      const kpis = `<div class="grid2" style="gap:12px;margin-bottom:14px">
        <div class="card" style="padding:16px"><div class="muted small">Spend (Google)</div><div style="font-size:22px;font-weight:700">${money(t.spend)}</div></div>
        <div class="card" style="padding:16px"><div class="muted small">Real commission</div><div style="font-size:22px;font-weight:700">${money(t.commission)}${t.pending ? ` <span class="muted small">+${money(t.pending)} pending</span>` : ''}</div></div>
        <div class="card" style="padding:16px"><div class="muted small">Real profit</div><div style="font-size:22px;font-weight:700;color:${t.profit >= 0 ? 'var(--good)' : 'var(--bad)'}">${money(t.profit)}</div></div>
        <div class="card" style="padding:16px"><div class="muted small">Real ROAS</div><div style="font-size:22px;font-weight:700">${t.real_roas == null ? '—' : t.real_roas.toFixed(2)}</div></div>
      </div>`;

      const recCards = rep.recommendations.length ? rep.recommendations.map((r, i) => `<div class="card rec-card" data-rec="${i}" style="margin-bottom:10px">
        <div class="card-body" style="display:flex;gap:12px;align-items:flex-start">
          <span class="pill ${sevClass(r.severity)}">${sevLabel(r.severity)}</span>
          <div class="grow"><div class="t" style="font-weight:600">${esc(r.title)}</div>
            <div class="muted small" style="margin:2px 0 4px">${esc(r.campaign_name)}</div>
            <div class="small">${esc(r.detail)}</div>
            <div class="row" style="gap:8px;margin-top:10px">
              ${r.action.type === 'pause' ? `<button class="btn sm danger" data-act="pause" data-cid="${esc(r.campaign_id)}" data-nm="${esc(r.campaign_name)}">${icon('pause', 'sm')} Pause campaign</button>` : ''}
              ${r.action.type === 'raise_budget' ? `<a class="btn sm primary" href="#campaign/${esc(r.campaign_id)}/settings">Adjust budget →</a>` : ''}
              ${r.type === 'tracking' ? `<button class="btn sm" data-track>Fix tracking</button>` : ''}
              <button class="btn sm ghost" data-dismiss="${i}">Dismiss</button>
            </div></div>
          ${r.impact > 0 ? `<div style="text-align:right"><div class="muted small">Impact</div><div style="font-weight:700">${money(r.impact)}</div></div>` : ''}
        </div></div>`).join('') : '<div class="card"><div class="card-body"><p class="muted" style="margin:0">No recommendations right now — connect a network and sync, or check back after more spend.</p></div></div>';

      const table = rep.campaigns.length ? `<div class="table-wrap"><table class="data"><thead><tr>
          <th class="l">Campaign</th><th>Spend</th><th>Commission</th><th>Pending</th><th>Real ROAS</th><th>Profit</th></tr></thead><tbody>
          ${rep.campaigns.map(c => `<tr><td class="l"><b>${esc(c.name)}</b><span class="sub">${esc(A.fmtId(c.campaign_id))}</span></td>
            <td>${money(c.spend)}</td><td>${money(c.commission)}</td><td class="muted">${c.pending ? money(c.pending) : '—'}</td>
            <td><b style="color:${c.real_roas == null ? 'inherit' : (c.real_roas >= 1.5 ? 'var(--good)' : (c.real_roas < 0.9 ? 'var(--bad)' : 'inherit'))}">${c.real_roas == null ? '—' : c.real_roas.toFixed(2)}</b></td>
            <td style="color:${c.profit >= 0 ? 'var(--good)' : 'var(--bad)'}">${money(c.profit)}</td></tr>`).join('')}
        </tbody></table></div>` : '<div class="card-body"><p class="muted" style="margin:0">No campaign spend in this period.</p></div>';

      // Affiliate commission pulled from the networks (matched + unmatched) — so data shows even
      // before campaign-level sub-id matching is in place.
      const s = aff.summary.totals;
      const c0 = s.currency || 'USD';
      const affPanel = (s.count > 0) ? `<section class="card"><div class="card-head"><h2>${icon('bars')} Affiliate revenue pulled <span class="muted small" style="font-weight:400">(last 60 days)</span></h2>
          ${aff.recent.length ? '<button class="btn sm" id="aff_toggle">Show conversions</button>' : ''}</div>
          <div class="card-body">
            <div class="grid2" style="gap:12px">
              <div><div class="muted small">Approved commission</div><div style="font-size:20px;font-weight:700;color:var(--good)">${amt(s.approved, c0)}</div></div>
              <div><div class="muted small">Pending</div><div style="font-size:20px;font-weight:700">${amt(s.pending, c0)}</div></div>
              <div><div class="muted small">Matched to campaigns</div><div style="font-size:20px;font-weight:700">${amt(s.matched, c0)}</div></div>
              <div><div class="muted small">Not matched yet</div><div style="font-size:20px;font-weight:700;color:${s.unmatched > 0 ? 'var(--warn,#92400e)' : 'inherit'}">${amt(s.unmatched, c0)}</div></div>
            </div>
            ${s.unmatched > 0 ? `<div class="alert warn" style="margin:12px 0 0"><b>${amt(s.unmatched, c0)} is pulled but not tied to a campaign yet.</b> Older affiliate links didn't carry the campaign id as a sub-id, so the optimizer can't attribute them automatically. Add <code>subId1={campaignid}</code> (Impact) / <code>clickref={campaignid}</code> (AWIN) via <b>Tracking setup</b> — future conversions will match on their own.</div>` : ''}
            <div id="aff_list" style="display:none;margin-top:12px"></div>
          </div></section>` : '';

      el.innerHTML = `
        ${rep.demo && A.state.demoLabels ? '<div class="alert info">Demo data — connect Impact/AWIN with real keys to optimize your live campaigns.</div>' : ''}
        <section class="card"><div class="card-head"><h2>${icon('link')} Affiliate networks</h2>
          <div class="tools"><button class="btn sm" id="opt_track">${icon('target', 'sm')} Tracking setup</button>${nets.networks.length ? `<button class="btn sm primary" id="opt_sync">${icon('refresh', 'sm')} Sync now</button>` : ''}</div></div>
          <div class="card-body">${netCards}</div></section>
        ${affPanel}
        ${kpis}
        <section class="card"><div class="card-head"><h2>${icon('auto')} Recommendations <span class="pill">${rep.recommendations.length}</span></h2></div>
          <div class="card-body" id="recs">${recCards}</div></section>
        <section class="card"><div class="card-head"><h2>${icon('bars')} Real revenue by campaign</h2></div>${table}</section>`;

      const reload = () => A.views.optimizer.render(el);
      $('#opt_track') && ($('#opt_track').onclick = trackingHelper);
      $('#aff_toggle') && ($('#aff_toggle').onclick = ev => {
        const box = $('#aff_list');
        if (box.style.display === 'none') {
          box.style.display = 'block'; ev.target.textContent = 'Hide conversions';
          const idCell = v => v ? `<span class="mono small" title="${esc(v)}">${esc(v.length > 16 ? v.slice(0, 16) + '…' : v)}</span>` : '<span class="muted">—</span>';
          box.innerHTML = `<div class="table-wrap"><table class="data"><thead><tr><th class="l">Date</th><th class="l">Network</th><th class="l">Sub-id</th><th class="l">Campaign</th><th class="l">Click ID</th><th class="l">Txn ID</th><th>Commission</th><th class="l">Status</th></tr></thead><tbody>
            ${aff.recent.map(x => `<tr><td class="l">${esc(x.date)}</td><td class="l">${esc(NET_NAMES[x.network] || x.network)}</td>
              <td class="l small">${x.sub_id ? esc(x.sub_id) : '<span class="muted">—</span>'}</td>
              <td class="l">${x.campaign_id ? esc(A.fmtId(x.campaign_id)) : '<span class="badge">unmatched</span>'}</td>
              <td class="l">${idCell(x.click_id)}</td>
              <td class="l">${idCell(x.txn_id)}</td>
              <td>${amt(x.commission, x.currency)}</td>
              <td class="l"><span class="status ${x.status === 'APPROVED' ? 'st-ENABLED' : (x.status === 'REVERSED' ? 'st-PAUSED' : '')}">${esc(x.status)}</span></td></tr>`).join('')}
          </tbody></table></div>`;
        } else { box.style.display = 'none'; ev.target.textContent = 'Show conversions'; }
      });
      $('#opt_sync') && ($('#opt_sync').onclick = e => A.busy(e.currentTarget, async () => {
        try { const r = await A.api('network_sync', {}, {}); A.toast(`Synced ${r.rows} conversions`); reload(); }
        catch (err) { A.showError(err); }
      }));
      el.addEventListener('click', async e => {
        const add = e.target.closest('[data-net-add]'); const edit = e.target.closest('[data-net-edit]');
        const rm = e.target.closest('[data-net-rm]'); const track = e.target.closest('[data-track]');
        const pause = e.target.closest('[data-act="pause"]'); const dis = e.target.closest('[data-dismiss]');
        if (add) return connectModal(add.dataset.netAdd, nets.fields[add.dataset.netAdd], null, reload);
        if (edit) { const a = nets.networks.find(x => x.network === edit.dataset.netEdit); return connectModal(edit.dataset.netEdit, nets.fields[edit.dataset.netEdit], a, reload); }
        if (rm) { if (await A.confirmBox('Remove this network connection? Stored conversions stay.', 'Remove', true)) { await A.api('network_remove', {}, { id: +rm.dataset.netRm }); A.toast('Removed'); reload(); } return; }
        if (track) return trackingHelper();
        if (dis) { const c = dis.closest('.rec-card'); if (c) c.style.display = 'none'; return; }
        if (pause) {
          if (!await A.confirmBox(`Pause <b>${esc(pause.dataset.nm)}</b>? Real ROAS is below break-even.`, 'Pause', true)) return;
          A.busy(pause, async () => {
            try { await A.post('set_campaign_status', { campaign_id: pause.dataset.cid, status: 'PAUSED', name: pause.dataset.nm }); A.toast('Campaign paused'); reload(); }
            catch (err) { A.showError(err); }
          });
        }
      });
    },
  };
})();
