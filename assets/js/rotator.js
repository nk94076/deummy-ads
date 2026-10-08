/* Suffix Rotator - rotate the Final URL suffix from Excel/CSV URLs every X minutes */
(() => {
  const { $, $$, esc, icon, state, fmtId } = A;
  const DAYS = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']];
  const INTERVALS = [[10, 'Every 10 minutes'], [15, 'Every 15 minutes'], [30, 'Every 30 minutes'], [60, 'Every hour'], [120, 'Every 2 hours'], [360, 'Every 6 hours'], [1440, 'Once a day']];
  const every = m => (INTERVALS.find(x => x[0] === m) || [0, `Every ${m} min`])[1];
  const rel = t => {
    if (!t) return '—';
    const s = (new Date(t.replace(' ', 'T')).getTime() - Date.now()) / 1000;
    const a = Math.abs(s), txt = a < 60 ? `${Math.round(a)} sec` : a < 3600 ? `${Math.round(a / 60)} min` : a < 86400 ? `${(a / 3600).toFixed(1)} hr` : `${Math.round(a / 86400)} d`;
    return s >= 0 ? `in ${txt}` : `${txt} ago`;
  };
  const daysTxt = d => d === '1234567' ? 'Roz' : d === '12345' ? 'Mon–Fri' : DAYS.filter(x => d.includes(x[0])).map(x => x[1]).join(', ');

  async function upload(file, text, rotatorId) {
    const fd = new FormData();
    if (file) fd.append('file', file);
    fd.append('text', text || '');
    if (rotatorId) fd.append('rotator_id', rotatorId);
    const res = await fetch('api.php?action=rotator_parse', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF': APP.csrf } });
    const d = await res.json().catch(() => ({ error: 'Could not read the server response' }));
    if (!res.ok || d.error) throw d;
    return d;
  }

  function editor(r, done) {
    const isNew = !r;
    r = r || { name: '', target: 'campaigns', campaign_ids: [], interval_min: 60, window_start: '00:00', window_end: '23:59', days: '1234567', mode: 'sequential', on_end: 'loop', single_use: true, extra_params: '', active: true, total: 0, used: 0, remaining: 0,
               conn_id: state.acc.conn, customer_id: state.acc.id, account_name: state.acc.name };
    const sameAcc = r.conn_id === state.acc?.conn && r.customer_id === state.acc?.id;
    let items = null;
    let base = '';
    const m = A.modal({
      title: isNew ? 'New Suffix Rotator' : 'Edit: ' + esc(r.name), wide: true,
      body: `<div class="alert info" style="margin-top:14px">Account: <b>${esc(r.account_name)}</b> (${fmtId(r.customer_id)}). The final URL stays the same; only the <b>Final URL suffix</b> changes. Changing an account/campaign suffix does not send ads back for review.</div>
        <label class="f">Rotator name</label><input id="rn" value="${esc(r.name)}" placeholder="e.g. Lycamobile Impact clickids">

        <div class="section-t">1. URLs / suffix list ${!isNew ? `<span class="muted" style="text-transform:none;font-weight:500">(${r.remaining} left, ${r.used} used${r.single_use ? ' — new uploads are added, already-used ones skipped' : ' — a new upload replaces the list'})</span>` : ''}</div>
        <label class="f">Excel (.xlsx) / CSV file</label><input type="file" id="file" accept=".xlsx,.csv,.txt,.tsv">
        <label class="f">Or paste URLs here <span class="muted">(one per line)</span></label>
        <textarea id="paste" rows="3" placeholder="https://www.lycamobile.us/en/?irclickid=AAA...&utm_source=impact&...&#10;https://www.lycamobile.us/en/?irclickid=BBB...&utm_source=impact&..."></textarea>
        <button type="button" class="btn" id="load">${icon('dl', 'sm')} Load file / text</button>
        <div id="parsed" style="margin-top:12px"></div>

        <div class="section-t">2. Where to apply</div>
        <label class="row small" style="gap:8px;margin:10px 0"><input type="radio" name="tg" value="campaigns" ${r.target === 'campaigns' ? 'checked' : ''}> Selected campaigns <span class="muted">(recommended)</span></label>
        <div id="cl" class="table-wrap" style="max-height:180px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:6px 10px;margin-left:24px">${sameAcc ? '<span class="spin"></span>' : '<span class="muted small">Select this account above to load its campaigns.</span>'}</div>
        <label class="row small" style="gap:8px;margin:10px 0"><input type="radio" name="tg" value="account" ${r.target === 'account' ? 'checked' : ''}> Whole account (account-level suffix, all campaigns)</label>

        <div class="section-t">3. Schedule</div>
        <div class="grid2">
          <div><label class="f">Rotate every</label><select id="iv">${INTERVALS.map(([v, l]) => `<option value="${v}" ${r.interval_min === v ? 'selected' : ''}>${l}</option>`).join('')}${INTERVALS.some(x => x[0] === r.interval_min) ? '' : `<option value="${r.interval_min}" selected>Every ${r.interval_min} min</option>`}<option value="custom">Custom (minutes)…</option></select>
            <input id="ivc" type="number" min="5" max="1440" placeholder="minutes (min 5)" class="hidden" style="margin-top:6px"></div>
          <div><label class="f">Time window <span class="muted">(${esc(APP.tz || 'server time')})</span></label>
            <div class="row" style="flex-wrap:nowrap"><input id="ws" type="time" value="${r.window_start}"><span class="muted">to</span><input id="we" type="time" value="${r.window_end}"></div>
            <div class="hint">The suffix only changes within this time window. Overnight like 22:00 to 06:00 works too.</div></div>
        </div>
        <label class="f">Days</label><div class="row" id="days">${DAYS.map(([v, l]) => `<label class="chip" style="cursor:pointer"><input type="checkbox" value="${v}" ${r.days.includes(v) ? 'checked' : ''} style="width:auto;margin-right:4px">${l}</label>`).join('')}</div>
        <div class="grid2">
          <div><label class="f">Order</label><select id="md"><option value="sequential">In list order (1, 2, 3…)</option><option value="random" ${r.mode === 'random' ? 'selected' : ''}>Random</option></select></div>
          <div><label class="f">When the list ends</label><select id="oe"><option value="loop">Start over (loop)</option><option value="stop" ${r.on_end === 'stop' ? 'selected' : ''}>Stop</option></select></div>
        </div>
        <label class="row f" style="gap:10px;margin-top:10px"><span class="switch"><input type="checkbox" id="su" ${r.single_use ? 'checked' : ''}><span></span></span> Single-use <span class="muted">(each suffix used once, then moved to "Used"; best for Impact click IDs)</span></label>

        <div class="section-t">4. Extra params (optional)</div>
        <input id="ex" value="${esc(r.extra_params)}" placeholder="subid1={campaignid}_{keyword}&subid2={gclid}">
        ${A.vtChips('ex')}
        <div class="hint">These params are set on every suffix. A param with the same name (e.g. an empty "subid1=") is replaced.</div>
        <div id="pvUrl" style="margin-top:12px"></div>

        <label class="row f" style="gap:10px;margin-top:18px"><span class="switch"><input type="checkbox" id="act" ${r.active ? 'checked' : ''}><span></span></span> Rotator ON (runs automatically via cron)</label>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">${isNew ? 'Create' : 'Save'}</button>`,
    });
    A.bindVt(m.el);
    const iv = m.$('#iv');
    iv.onchange = () => m.$('#ivc').classList.toggle('hidden', iv.value !== 'custom');

    const previewUrl = () => {
      const first = items ? items[0] : r.last_suffix;
      if (!first) { m.$('#pvUrl').innerHTML = ''; return; }
      const ex = m.$('#ex').value.replace(/^[?&]+/, '');
      const pairs = new Map();
      [...first.split('&'), ...(ex ? ex.split('&') : [])].filter(Boolean).forEach(p => pairs.set(p.split('=')[0], p));
      const sfx = [...pairs.values()].join('&');
      const b = base || '<final URL>';
      m.$('#pvUrl').innerHTML = `<div class="small muted">On click, the final URL becomes (first suffix):</div><div class="example">${esc(b)}${b.includes('?') ? '&' : '?'}${esc(sfx)}</div>`;
    };
    m.$('#ex').addEventListener('input', previewUrl);
    previewUrl();

    m.$('#load').onclick = e => A.busy(e.currentTarget, async () => {
      const f = m.$('#file').files[0], t = m.$('#paste').value;
      if (!f && !t.trim()) return A.toast('Choose a file or paste URLs');
      try {
        const d = await upload(f, t, isNew ? 0 : r.id);
        items = d.items;
        const bases = Object.entries(d.bases || {});
        base = bases[0]?.[0] || '';
        const dd = d.dedup;
        const dedupTxt = dd ? ` · <b>${dd.new}</b> new${dd.dup_used ? `, ${dd.dup_used} already used (skipped)` : ''}${dd.dup_pool ? `, ${dd.dup_pool} already in list` : ''}` : '';
        m.$('#parsed').innerHTML = `<div class="alert ok"><b>${d.count}</b> suffixes found${d.duplicates ? ` · ${d.duplicates} duplicates removed` : ''}${d.skipped ? ` · ${d.skipped} rows skipped (no URL/suffix)` : ''}${dedupTxt}.
          ${d.count ? ` ${m.$('#iv').value !== 'custom' ? `At ${every(+m.$('#iv').value).toLowerCase()}, the list finishes in ~${fmtDur(d.count * +m.$('#iv').value)}.` : ''}` : ''}</div>
          ${bases.length > 1 ? `<div class="alert warn">The file has ${bases.length} different final URLs (${bases.map(([b, n]) => `${esc(b)} ×${n}`).join(', ')}). The rotator only sets the suffix; the final URL in Google Ads is unchanged.</div>` : ''}
          <div class="diff" style="max-height:130px;overflow:auto;background:#f8fafc;border-radius:8px;padding:8px 10px">${items.slice(0, 5).map((s, i) => `<div>#${i + 1} ${esc(s)}</div>`).join('')}${items.length > 5 ? `<div class="muted">… and ${items.length - 5} more</div>` : ''}</div>`;
        previewUrl();
      } catch (err) { m.$('#parsed').innerHTML = `<div class="alert err">${esc(err.error || err)}</div>`; }
    });

    if (sameAcc) {
      A.loadReport().then(rep => {
        if (!rep.campaigns) { m.$('#cl').textContent = 'Could not load campaigns'; return; }
        m.$('#cl').innerHTML = rep.campaigns.map(c => `<label class="row small" style="gap:8px;padding:4px 0"><input type="checkbox" value="${c.id}" ${r.campaign_ids.includes(c.id) ? 'checked' : ''} style="width:auto">${esc(c.name)} <span class="muted">(${c.status === 'ENABLED' ? 'Active' : 'Paused'})</span></label>`).join('') || '<span class="muted small">No campaigns</span>';
      }).catch(() => { m.$('#cl').textContent = 'Could not load the campaign list'; });
    }

    m.$('#sv').onclick = e => A.busy(e.currentTarget, async () => {
      if (isNew && !items) return A.toast('Load a file or URLs first');
      const ivv = iv.value === 'custom' ? +m.$('#ivc').value : +iv.value;
      if (!ivv || ivv < 5) return A.toast('Minimum interval is 5 minutes');
      const campaign_ids = sameAcc ? $$('#cl input:checked', m.el).map(x => x.value) : r.campaign_ids;
      const data = {
        id: r.id, name: m.$('#rn').value, conn: r.conn_id, cid: r.customer_id, account_name: r.account_name,
        target: m.el.querySelector('input[name=tg]:checked').value, campaign_ids,
        interval_min: ivv, window_start: m.$('#ws').value, window_end: m.$('#we').value,
        days: $$('#days input:checked', m.el).map(x => x.value).join(''), mode: m.$('#md').value, on_end: m.$('#oe').value,
        single_use: m.$('#su').checked, extra_params: m.$('#ex').value, active: m.$('#act').checked,
      };
      if (items) data.items = items;
      try { await A.post('rotator_save', { data }); m.close(); A.toast('Rotator saved'); done(); }
      catch (err) { let b = m.$('#rErr'); if (!b) { m.$('.modal-body').insertAdjacentHTML('beforeend', '<div class="alert err" id="rErr" style="margin-top:14px"></div>'); b = m.$('#rErr'); } b.textContent = err.error || 'Error'; b.scrollIntoView({ block: 'nearest' }); }
    });
  }
  const fmtDur = min => min < 60 ? `${min} min` : min < 1440 ? `${(min / 60).toFixed(1)} hr` : `${(min / 1440).toFixed(1)} d`;

  function reportModal(r) {
    const m = A.modal({ title: 'Suffix report: ' + esc(r.name), wide: true, body: '<p style="padding:20px;text-align:center"><span class="spin"></span></p>', foot: '<button class="btn" id="csv">CSV</button><button class="btn primary" data-close>Close</button>' });
    let last = null;
    const load = () => A.api('rotator_report', { id: r.id }).then(d => {
      last = d;
      // Aggregate est. clicks PER suffix, then only flag ones that likely took a whole click
      // (cumulative >= 1). A loose "est > 0" over-counts badly: a few real clicks get spread as
      // 0.1-0.2 fractions across many live windows, so dozens of suffixes would show est > 0.
      const estBySeq = {};
      if (d.has_estimate) d.rows.forEach(x => { if (!x.clicked) estBySeq[x.item_seq] = (estBySeq[x.item_seq] || 0) + (x.est_clicks || 0); });
      const clickedSeqs = Object.keys(estBySeq).filter(s => estBySeq[s] >= 1).map(Number);
      const head = `<div class="row small" style="gap:16px;margin-bottom:12px;flex-wrap:wrap">
        <span><b>${d.remaining}</b> left</span><span><b>${d.used}</b> used</span><span><b>${d.clicked_count || 0}</b> clicked/trashed</span><span><b>${d.total_applied}</b> rotations logged</span>
        ${d.has_estimate ? `<span>~<b>${d.est_total_clicks}</b> est. clicks total</span>` : ''}</div>
        ${d.note ? `<div class="alert warn" style="margin:0 0 12px">${esc(d.note)}</div>` : ''}
        <div class="hint" style="margin-bottom:10px">Each row is one suffix while it was live. <b>Est. clicks</b> comes from the account/campaign hourly clicks in that window — Google can't report clicks per suffix, so treat it as an estimate. <b>Trash</b> retires a suffix: it won't rotate again, even when the loop restarts. ${clickedSeqs.length ? `<button class="btn sm" id="trashClicked" style="margin-top:6px">${icon('trash', 'sm')} Move ${clickedSeqs.length} suffix(es) with ~1+ est. click to trash</button> <span class="muted small">(estimate — use per-row Trash for exact)</span>` : ''}</div>`;
      m.$('.modal-body').innerHTML = head + (d.rows.length
        ? `<div class="table-wrap" style="max-height:52vh;overflow:auto"><table class="data"><thead><tr><th class="l nosort">Applied</th><th class="nosort">Live</th><th class="nosort">Est. clicks</th><th class="l nosort">Suffix</th><th class="nosort"></th></tr></thead><tbody>
          ${d.rows.map(x => `<tr${x.clicked ? ' style="opacity:.55"' : ''}><td class="l">${esc(x.applied_at)}<span class="sub">${esc(x.source)}</span></td><td>${x.minutes} min</td><td>${d.has_estimate ? x.est_clicks : '—'}</td>
            <td class="l diff" style="max-width:420px;white-space:normal">${esc(x.suffix)}${x.clicked ? ' <span class="badge">trashed</span>' : ''}</td>
            <td>${x.clicked ? `<button class="btn sm" data-untrash="${x.item_seq}">Restore</button>` : `<button class="btn sm danger" data-trash="${x.item_seq}">${icon('trash', 'sm')} Trash</button>`}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">No suffix applied yet.</p>');
      m.$('#csv').onclick = () => A.csvDownload(`suffix_report_${r.id}.csv`, ['Applied at', 'Minutes live', 'Est. clicks', 'Suffix', 'Trashed'], d.rows.map(x => [x.applied_at, x.minutes, x.est_clicks, x.suffix, x.clicked ? 'yes' : '']));
      const tc = m.$('#trashClicked');
      if (tc) tc.onclick = () => trash(clickedSeqs, true);
      m.$('.modal-body').onclick = e => {
        const t = e.target.closest('[data-trash]'); const u = e.target.closest('[data-untrash]');
        if (t) trash([+t.dataset.trash], true);
        else if (u) trash([+u.dataset.untrash], false);
      };
    }).catch(e => { m.$('.modal-body').innerHTML = `<div class="alert err" style="margin-top:14px">${esc(e.error || e)}</div>`; });
    const trash = async (seqs, clicked) => {
      if (!seqs.length) return;
      try { const res = await A.api('rotator_trash', {}, { id: r.id, seqs, clicked }); A.toast(clicked ? `${res.n} moved to trash` : `${res.n} restored`); load(); }
      catch (err) { A.showError(err); }
    };
    load();
  }

  function logModal(r) {
    const m = A.modal({ title: 'Log: ' + esc(r.name), wide: true, body: '<p style="padding:20px;text-align:center"><span class="spin"></span></p>', foot: '<button class="btn" id="csv">CSV</button><button class="btn primary" data-close>Close</button>' });
    A.api('rotator_log', { id: r.id }).then(d => {
      m.$('.modal-body').innerHTML = d.log.length ? `<div class="table-wrap" style="max-height:60vh;overflow:auto"><table class="data"><thead><tr><th class="l nosort">Time</th><th class="nosort">#</th><th class="l nosort">Suffix</th><th class="l nosort">Result</th></tr></thead><tbody>
        ${d.log.map(l => `<tr><td class="l">${esc(l.applied_at)}<span class="sub">${esc(l.source)}</span></td><td>${l.item_seq + 1}</td><td class="l diff" style="max-width:420px;white-space:normal">${esc(l.suffix)}</td><td class="l ${+l.ok ? 'good' : 'bad'}" style="white-space:normal">${+l.ok ? '✓ ' : '✗ '}${esc(l.message || '')}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="muted" style="margin-top:14px">No suffix applied yet.</p>';
      m.$('#csv').onclick = () => A.csvDownload(`suffix_log_${r.id}.csv`, ['Time', 'Item #', 'Suffix', 'OK', 'Message', 'Source'], d.log.map(l => [l.applied_at, l.item_seq + 1, l.suffix, l.ok, l.message, l.source]));
    }).catch(e => { m.$('.modal-body').innerHTML = `<div class="alert err" style="margin-top:14px">${esc(e.error || e)}</div>`; });
  }

  A.views.rotator = {
    eyebrow: 'URL Rotator', dates: false,
    async render(el) {
      el.innerHTML = `<section class="card"><div class="card-head"><h2>${icon('refresh')} Suffix Rotator</h2>
          ${APP.canEdit ? `<button class="btn primary" id="newR">${icon('plus', 'sm')} New rotator (${esc(state.acc.name)})</button>` : ''}</div>
          <div class="card-body muted">Upload the Excel or CSV from your affiliate network. The tool takes the part after "?" from each URL and applies them one by one to the <b>Final URL suffix</b> in Google Ads on your schedule (every 10 min, 1 hour…). The final URL stays the same. Every change is logged so you can reconcile with the network.</div>
          <div id="rl"><p style="padding:24px;text-align:center"><span class="spin"></span></p></div></section>
        <section class="card"><div class="card-head"><h2>${icon('bulb')} Cron setup (required)</h2></div><div class="card-body">
          Add a cron in Hostinger > Advanced > Cron Jobs that runs <b>every 5 minutes</b> so the rotator fires on time:
          <div class="example" style="margin-top:8px">/usr/bin/php ${esc('/home/USERNAME/public_html/ads/cron.php')} YOUR_CRON_KEY suffix</div>
          <div class="hint">YOUR_CRON_KEY = the cron_key from config.php. Server time: <b id="srvT">…</b></div></div></section>`;
      const load = async () => {
        const d = await A.api('rotators');
        APP.tz = d.timezone;
        if ($('#srvT')) $('#srvT').textContent = `${d.now} (${d.timezone})`;
        if (!$('#rl')) return [];
        $('#rl').innerHTML = d.rotators.length ? d.rotators.map(r => {
          const pct = r.total ? Math.min(100, Math.round(r.pos / r.total * 100)) : 0;
          return `<div class="list-item" style="align-items:flex-start">
            <span class="switch" title="ON/OFF" style="margin-top:4px"><input type="checkbox" data-tg="${r.id}" ${r.active ? 'checked' : ''}><span></span></span>
            <div class="grow">
              <div class="t">${esc(r.name)} ${r.active ? (r.in_window ? '<span class="status st-ENABLED">Running</span>' : '<span class="status st-PAUSED">Outside window</span>') : '<span class="status st-PAUSED">Off</span>'}</div>
              <div class="small">${esc(r.account_name)} (${fmtId(r.customer_id)}) · ${r.target === 'account' ? 'Whole account' : r.campaign_ids.length + ' campaign(s)'} · ${every(r.interval_min)} · ${r.window_start}–${r.window_end} · ${daysTxt(r.days)} · ${r.mode === 'random' ? 'Random' : 'In order'}${r.single_use ? ', single-use' : (r.on_end === 'stop' ? ', stop at end' : ', loop')}</div>
              <div class="row small" style="margin:8px 0 4px;gap:10px"><div style="flex:1;max-width:320px;height:8px;background:#eef2f7;border-radius:99px;overflow:hidden"><div style="width:${r.total ? Math.round(r.used / r.total * 100) : pct}%;height:100%;background:var(--accent)"></div></div>
                <span>${r.single_use ? `${r.remaining} left · ${r.used} used` : (r.mode === 'random' ? `${r.total} suffix · ${r.used} used` : `${r.pos}/${r.total} used`)}${r.clicked ? ` · <b style="color:var(--bad)">${r.clicked} clicked</b>` : ''}</span>
                <span class="muted">· Next: ${r.active ? rel(r.next_run_at) : '—'} · Last: ${r.last_run_at ? rel(r.last_run_at) : 'never'}</span></div>
              ${r.last_suffix ? `<div class="diff muted" style="max-width:720px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.last_suffix)}">Current: ${esc(r.last_suffix)}</div>` : ''}
              ${r.last_error ? `<div class="small bad">Error: ${esc(r.last_error)}</div>` : ''}
            </div>
            <div class="row" style="justify-content:flex-end">
              ${APP.canEdit ? `<button class="btn sm" data-run="${r.id}" title="Apply the next suffix now">${icon('play', 'sm')} Run now</button>` : ''}
              <button class="btn sm" data-report="${r.id}" title="Which suffix was live when, with estimated clicks">${icon('bars', 'sm')} Report</button>
              <button class="btn sm" data-log="${r.id}">${icon('line', 'sm')} Log</button>
              ${APP.canEdit ? `<button class="btn sm" data-ed="${r.id}">${icon('edit', 'sm')}</button><button class="btn sm danger" data-del="${r.id}">${icon('trash', 'sm')}</button>` : ''}
            </div></div>`;
        }).join('') : '<p class="muted" style="padding:0 24px 22px">No rotators yet. Create one with "New rotator".</p>';
        return d.rotators;
      };
      let list = [];
      try { list = await load(); } catch (e) { A.showError(e); }
      const timer = setInterval(() => { if (state.view !== 'rotator' || !$('#rl')) return clearInterval(timer); load().then(x => list = x).catch(() => {}); }, 30000);

      el.onclick = async e => {
        if (e.target.closest('#newR')) return editor(null, () => load().then(x => list = x));
        const b = e.target.closest('[data-run],[data-log],[data-report],[data-ed],[data-del]'); if (!b) return;
        const r = list.find(x => x.id === +(b.dataset.run || b.dataset.log || b.dataset.report || b.dataset.ed || b.dataset.del));
        if (b.dataset.report) return reportModal(r);
        if (b.dataset.log) return logModal(r);
        if (b.dataset.ed) return editor(r, () => load().then(x => list = x));
        if (b.dataset.del) {
          if (await A.confirmBox(`Delete rotator "${esc(r.name)}"? (The suffix currently set in Google Ads stays as is.)`, 'Delete', true)) {
            await A.post('rotator_delete', { id: r.id }); list = await load(); A.toast('Deleted');
          }
          return;
        }
        if (b.dataset.run) {
          if (!await A.confirmBox(`Apply the next suffix for "${esc(r.name)}" to Google Ads now?`)) return;
          A.busy(b, async () => {
            try {
              const x = await A.post('rotator_run', { id: r.id });
              if (x.skipped) A.toast(x.reason || 'Nothing applied.');
              else A.toast(`Applied suffix #${x.seq + 1} (${x.updated} updated)`);
              list = await load();
            }
            catch (err) { A.showError(err); list = await load(); }
          });
        }
      };
      el.onchange = async e => {
        const tg = e.target.closest('[data-tg]'); if (!tg) return;
        try { await A.post('rotator_toggle', { id: +tg.dataset.tg, active: tg.checked }); A.toast('Rotator ' + (tg.checked ? 'ON' : 'OFF')); list = await load(); }
        catch (err) { A.showError(err); tg.checked = !tg.checked; }
      };
    },
  };
})();
