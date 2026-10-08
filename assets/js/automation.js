/* Automation rules */
(() => {
  const { $, $$, esc, icon, state, dec, money } = A;
  let METRICS = {};

  function ruleText(r) {
    return r.conditions.map(c => `${METRICS[c.metric] || c.metric} ${c.op === 'gt' ? '>' : '<'} ${c.value}`).join(' <b>and</b> ') +
      ` (last ${r.days} days) → <b>${r.action === 'PAUSE' ? 'Pause' : 'Enable'}</b>`;
  }

  function ruleModal(r, done) {
    const isNew = !r;
    r = r || { name: '', days: 7, action: 'PAUSE', enabled: true, conditions: [{ metric: 'cost', op: 'gt', value: 2000 }, { metric: 'conv', op: 'lt', value: 1 }], campaign_ids: [] };
    const condRow = c => `<div class="cond"><select class="m">${Object.entries(METRICS).map(([k, l]) => `<option value="${k}" ${c.metric === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <select class="o"><option value="gt" ${c.op === 'gt' ? 'selected' : ''}>&gt; greater</option><option value="lt" ${c.op === 'lt' ? 'selected' : ''}>&lt; less</option></select>
      <input class="v" type="number" step="any" value="${c.value}"><button type="button" class="btn icon sm" data-rm>${icon('x', 'sm')}</button></div>`;
    const m = A.modal({
      title: isNew ? 'New automation rule' : 'Edit rule', wide: true,
      body: `<div class="alert info" style="margin-top:14px">Account: <b>${esc(state.acc.name)}</b> (${A.fmtId(state.acc.id)}). The rule runs hourly via cron (setup in the README). Use "Preview" to see which campaigns will match.</div>
        <label class="f">Rule name</label><input id="rn" value="${esc(r.name)}" placeholder="e.g. Pause on zero conversions">
        <label class="f">If (all conditions are true)</label><div id="conds">${r.conditions.map(condRow).join('')}</div>
        <button type="button" class="btn sm" id="addC">${icon('plus', 'sm')} Add condition</button>
        <div class="grid2"><div><label class="f">Days of data to use</label><input id="days" type="number" min="1" max="90" value="${r.days}"></div>
        <div><label class="f">Then do this</label><select id="act"><option value="PAUSE">Pause the campaign</option><option value="ENABLE" ${r.action === 'ENABLE' ? 'selected' : ''}>Enable the campaign</option></select></div></div>
        <label class="f">Which campaigns <span class="muted">(none selected = all)</span></label>
        <div id="cl" class="table-wrap" style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:6px 10px"><span class="spin"></span></div>
        <label class="row f" style="gap:10px"><span class="switch"><input type="checkbox" id="en" ${r.enabled ? 'checked' : ''}><span></span></span> Rule ON (runs automatically via cron)</label>
        <div id="pvOut"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn" id="pv">${icon('eye', 'sm')} Save & Preview</button><button class="btn primary" id="sv">Save</button>`,
    });
    A.loadReport().then(rep => {
      m.$('#cl').innerHTML = rep.campaigns ? rep.campaigns.map(c => `<label class="row small" style="gap:8px;padding:4px 0"><input type="checkbox" value="${c.id}" ${r.campaign_ids.includes(c.id) ? 'checked' : ''}>${esc(c.name)} <span class="muted">(${esc(c.status === 'ENABLED' ? 'Active' : 'Paused')})</span></label>`).join('') : '—';
    }).catch(() => { m.$('#cl').textContent = 'Could not load the campaign list'; });
    m.$('#addC').onclick = () => m.$('#conds').insertAdjacentHTML('beforeend', condRow({ metric: 'roas', op: 'lt', value: 1 }));
    m.$('#conds').onclick = e => { if (e.target.closest('[data-rm]')) e.target.closest('.cond').remove(); };
    const collect = () => ({
      id: r.id, name: m.$('#rn').value, days: m.$('#days').value, action: m.$('#act').value, enabled: m.$('#en').checked,
      conn: state.acc.conn, cid: state.acc.id, account_name: state.acc.name,
      conditions: $$('.cond', m.el).map(x => ({ metric: x.querySelector('.m').value, op: x.querySelector('.o').value, value: x.querySelector('.v').value })),
      campaign_ids: $$('#cl input:checked', m.el).map(x => x.value),
    });
    const save = async () => { const d = await A.post('rule_save', { data: collect() }); r.id = d.rule.id; return d.rule; };
    m.$('#sv').onclick = e => A.busy(e.currentTarget, async () => {
      try { await save(); m.close(); A.toast('Rule saved'); done(); } catch (err) { m.$('#pvOut').innerHTML = `<div class="alert err" style="margin-top:14px">${esc(err.error || err)}</div>`; }
    });
    m.$('#pv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        const rule = await save();
        const p = await A.post('rule_run', { id: rule.id, apply: false });
        m.$('#pvOut').innerHTML = previewHtml(p);
        done(false);
      } catch (err) { m.$('#pvOut').innerHTML = `<div class="alert err" style="margin-top:14px">${esc(err.error || err)}</div>`; }
    });
  }

  const previewHtml = p => `<div class="alert ${p.matches.length ? 'warn' : 'ok'}" style="margin-top:16px"><b>Preview (${p.from} to ${p.to}):</b> ${p.matches.length ? `${p.matches.length} campaign(s) matched` : 'No campaigns matched'}</div>
    ${p.matches.length ? `<table class="data"><thead><tr><th class="l nosort">Campaign</th><th class="nosort">Cost</th><th class="nosort">Conv.</th><th class="nosort">CPA</th><th class="nosort">ROAS</th></tr></thead><tbody>
    ${p.matches.map(x => `<tr><td class="l">${esc(x.name)}</td><td>${money(x.cost)}</td><td>${dec(x.conv)}</td><td>${x.cpa == null ? '—' : money(x.cpa)}</td><td>${x.roas == null ? '—' : dec(x.roas) + 'x'}</td></tr>`).join('')}</tbody></table>` : ''}`;

  A.views.automation = {
    eyebrow: 'Automation', dates: false,
    async render(el) {
      el.innerHTML = `<section class="card"><div class="card-head"><h2>${icon('auto')} Automation rules</h2>
        ${APP.canEdit ? `<button class="btn primary" id="newR">${icon('plus', 'sm')} New rule (${esc(state.acc.name)})</button>` : ''}</div>
        <div id="rl"><p style="padding:24px;text-align:center"><span class="spin"></span></p></div></section>
        <section class="card"><div class="card-head"><h2>${icon('bulb')} How it works</h2></div><div class="card-body muted">
        Rules run hourly via <code>cron.php</code> (set up in Hostinger > Cron Jobs; see the README). Each rule applies to one account.
        Example rules: <b>Cost &gt; 2000 and Conversions &lt; 1 (7 days) → Pause</b>, <b>ROAS &lt; 0.8 (14 days) → Pause</b>, <b>CPA &gt; 500 (7 days) → Pause</b>.
        Every change appears in Tools &gt; Change log.</div></section>`;
      const load = async () => {
        const d = await A.api('rules');
        METRICS = d.metrics;
        $('#rl').innerHTML = d.rules.length ? d.rules.map(r => `<div class="list-item">
          <span class="switch" title="ON/OFF"><input type="checkbox" data-tg="${r.id}" ${r.enabled ? 'checked' : ''}><span></span></span>
          <div class="grow"><div class="t">${esc(r.name)}</div><div class="small">${ruleText(r)}</div>
          <div class="muted small">${esc(r.account_name)} (${A.fmtId(r.cid)})${r.campaign_ids.length ? ` · ${r.campaign_ids.length} campaign(s)` : ' · all campaigns'}${r.last_run ? ` · Last run ${esc(r.last_run)}: ${esc(r.last_result || '')}` : ' · not run yet'}</div></div>
          <button class="btn sm" data-pv="${r.id}">${icon('eye', 'sm')} Preview</button>
          ${APP.canEdit ? `<button class="btn sm" data-run="${r.id}">${icon('play', 'sm')} Run now</button><button class="btn sm" data-ed="${r.id}">${icon('edit', 'sm')}</button><button class="btn sm danger" data-del="${r.id}">${icon('trash', 'sm')}</button>` : ''}
        </div>`).join('') : '<p class="muted" style="padding:0 24px 22px">No rules yet. Create one with "New rule".</p>';
        return d.rules;
      };
      let rules = [];
      try { rules = await load(); } catch (e) { A.showError(e); }
      el.onclick = async e => {
        if (e.target.closest('#newR')) return ruleModal(null, () => load().then(x => rules = x));
        const t = e.target.closest('[data-pv],[data-run],[data-ed],[data-del]'); if (!t) return;
        const r = rules.find(x => x.id === (t.dataset.pv || t.dataset.run || t.dataset.ed || t.dataset.del));
        if (t.dataset.ed) {
          if (A.key({ conn: r.conn, id: r.cid }) !== A.key(state.acc)) return A.toast('Select this rule\u2019s account above, then edit');
          return ruleModal(r, () => load().then(x => rules = x));
        }
        if (t.dataset.del) { if (await A.confirmBox(`Delete rule "${esc(r.name)}"?`, 'Delete', true)) { await A.post('rule_delete', { id: r.id }); rules = await load(); A.toast('Rule deleted'); } return; }
        const apply = !!t.dataset.run;
        if (apply && !await A.confirmBox(`Run rule "${esc(r.name)}" now? Matching campaigns will be ${r.action === 'PAUSE' ? 'paused' : 'enabled'}.`)) return;
        A.busy(t, async () => {
          try {
            const p = await A.post('rule_run', { id: r.id, apply });
            A.modal({ title: (apply ? 'Result: ' : 'Preview: ') + esc(r.name), wide: true, body: previewHtml(p) + (apply ? `<p><b>${p.applied}</b> campaign(s) ${r.action === 'PAUSE' ? 'paused' : 'enabled'}.</p>` : ''), foot: '<button class="btn" data-close>Close</button>' });
            if (apply) { rules = await load(); state.report = null; }
          } catch (err) { A.showError(err); }
        });
      };
      el.onchange = async e => {
        const tg = e.target.closest('[data-tg]'); if (!tg) return;
        const r = rules.find(x => x.id === tg.dataset.tg);
        try { await A.post('rule_save', { data: { ...r, enabled: tg.checked } }); A.toast('Rule ' + (tg.checked ? 'ON' : 'OFF')); rules = await load(); }
        catch (err) { A.showError(err); tg.checked = !tg.checked; }
      };
    },
  };
})();
