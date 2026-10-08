/* Campaign detail: Settings | Ad groups | Ads & URLs | Keywords | Search terms */
(() => {
  const { $, $$, esc, icon, state, num, dec, money, derive, statusPill, label } = A;
  const VT = ['{lpurl}', '{gclid}', '{campaignid}', '{adgroupid}', '{keyword}', '{matchtype}', '{device}', '{network}', '{creative}', '{targetid}', '{loc_physical_ms}'];
  const TABS = [['settings', 'Settings & tracking'], ['targeting', 'Locations & language'], ['adgroups', 'Ad groups'], ['ads', 'Ads & final URLs'], ['keywords', 'Keywords'], ['terms', 'Search terms']];

  /** ValueTrack chips - insert into the focused input */
  const vtChips = target => `<div class="row" style="gap:6px;margin-top:6px">${VT.map(v => `<button type="button" class="chip" data-vt="${v}" data-t="${target}">${v}</button>`).join('')}</div>`;
  function bindVt(root) {
    root.addEventListener('click', e => {
      const c = e.target.closest('[data-vt]'); if (!c) return;
      const inp = root.querySelector('#' + c.dataset.t); if (!inp) return;
      const s = inp.selectionStart ?? inp.value.length;
      inp.value = inp.value.slice(0, s) + c.dataset.vt + inp.value.slice(inp.selectionEnd ?? s);
      inp.focus();
    });
  }
  A.bindVt = bindVt; A.vtChips = vtChips;

  const trackingFields = (d, pre = '') => `
    <label class="f">Tracking template <span class="muted">(optional)</span></label>
    <input id="${pre}tpl" value="${esc(d.tracking_url_template || '')}" placeholder="{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}">
    ${vtChips(pre + 'tpl')}
    <div class="hint">{lpurl} = your final URL. For a redirect tracker: https://track.domain.com/click?url={lpurl}&gclid={gclid}</div>
    <label class="f">Final URL suffix <span class="muted">(optional, no leading ?)</span></label>
    <input id="${pre}sfx" value="${esc(d.final_url_suffix || '')}" placeholder="utm_source=google&utm_medium=cpc&gclid={gclid}">
    ${vtChips(pre + 'sfx')}
    <div class="hint">The suffix is appended to the final URL. The easiest way to change tracking without editing landing pages or ads.</div>`;

  async function guard(p, el) {
    try { const r = await p; if (r && r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return null; } return r; }
    catch (e) { A.showError(e); el.innerHTML = ''; return null; }
  }
  const loading = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';

  A.views.campaign = {
    eyebrow: 'Campaigns',
    async render(el, [id, tab = 'settings']) {
      if (!id) { location.hash = '#campaigns'; return; }
      el.innerHTML = loading;
      const d = await guard(A.get('campaign', { campaign_id: id }), el);
      if (!d) return;
      const c = d.campaign;
      el.innerHTML = `<a class="back" href="#campaigns">${icon('back', 'sm')} All campaigns</a>
        <div class="row" style="justify-content:space-between;align-items:flex-start">
          <div><h2 style="font-size:22px">${esc(c.name)}</h2>
          <div class="meta-chips">${statusPill(c.status)}<span class="chip">${esc(label(c.type))}</span><span class="chip">${esc(label(c.bidding))}</span>
          <span class="chip">Budget ${money(c.budget)}/day</span>${c.serving_status && c.serving_status !== 'SERVING' ? `<span class="chip">${esc(label(c.serving_status))}</span>` : ''}</div></div>
        </div>
        <div class="tabs" id="tabs">${TABS.map(([k, l]) => `<button data-tab="${k}" class="${k === tab ? 'on' : ''}">${l}</button>`).join('')}</div>
        <div id="tabBody"></div>`;
      $('#tabs').onclick = e => { const b = e.target.closest('[data-tab]'); if (b) location.hash = `#campaign/${id}/${b.dataset.tab}`; };
      const body = $('#tabBody');
      ({ settings, targeting: A.targetingTab, adgroups, ads, keywords, terms })[tab]?.(body, c);
    },
  };

  // ================= Settings =================
  function settings(el, c) {
    const ro = !APP.canEdit;
    el.innerHTML = `<section class="card"><div class="form-card" id="cf">
      <div class="section-t">General</div>
      <div class="grid2">
        <div><label class="f">Campaign name</label><input id="name" value="${esc(c.name)}"></div>
        <div><label class="f">Status</label><select id="status"><option value="ENABLED" ${c.status === 'ENABLED' ? 'selected' : ''}>Active</option><option value="PAUSED" ${c.status === 'PAUSED' ? 'selected' : ''}>Paused</option></select></div>
        <div><label class="f">Daily budget (${esc(A.state.report?.currency || A.state.acc.currency || '')})</label>
          <input id="budget" type="number" min="1" step="0.01" value="${c.budget}" ${c.budget_shared ? 'disabled' : ''}>
          ${c.budget_shared ? '<div class="hint">Shared budget - change it in Google Ads > Shared library.</div>' : ''}</div>
        <div><label class="f">Bidding strategy</label><input value="${esc(label(c.bidding))}" disabled></div>
        ${c.can_target_cpa ? `<div><label class="f">Target CPA <span class="muted">(empty = no target)</span></label><input id="tcpa" type="number" step="0.01" value="${c.target_cpa ?? ''}"></div>` : ''}
        ${c.can_target_roas ? `<div><label class="f">Target ROAS <span class="muted">(2.5 = 250%)</span></label><input id="troas" type="number" step="0.01" value="${c.target_roas ?? ''}"></div>` : ''}
      </div>
      <div class="section-t">Tracking & URL options</div>
      ${trackingFields(c)}
      <label class="f">Custom parameters <span class="muted">(used as {_name} in the tracking template)</span></label>
      <div id="params">${(c.custom_params.length ? c.custom_params : [{ key: '', value: '' }]).map(p => paramRow(p)).join('')}</div>
      <button type="button" class="btn sm" id="addParam">${icon('plus', 'sm')} Add parameter</button>
      <div class="save-bar"><button class="btn" id="reset">Reset</button><button class="btn primary" id="save" ${ro ? 'disabled' : ''}>${icon('check', 'sm')} Save changes</button></div>
    </div></section>`;
    bindVt(el);
    $('#addParam').onclick = () => $('#params').insertAdjacentHTML('beforeend', paramRow({ key: '', value: '' }));
    $('#params').onclick = e => { if (e.target.closest('[data-rm]')) e.target.closest('.kv').remove(); };
    $('#reset').onclick = () => A.route();
    $('#save').onclick = async e => {
      const data = {};
      if ($('#name').value.trim() !== c.name) data.name = $('#name').value.trim();
      if ($('#status').value !== c.status) data.status = $('#status').value;
      if (!c.budget_shared && +$('#budget').value !== +c.budget) data.budget = $('#budget').value;
      if ($('#tcpa') && $('#tcpa').value !== String(c.target_cpa ?? '')) data.target_cpa = $('#tcpa').value;
      if ($('#troas') && $('#troas').value !== String(c.target_roas ?? '')) data.target_roas = $('#troas').value;
      if ($('#tpl').value !== c.tracking_url_template) data.tracking_url_template = $('#tpl').value;
      if ($('#sfx').value !== c.final_url_suffix) data.final_url_suffix = $('#sfx').value;
      const params = $$('#params .kv').map(r => ({ key: r.querySelector('.k').value.trim(), value: r.querySelector('.v').value })).filter(p => p.key);
      if (JSON.stringify(params) !== JSON.stringify(c.custom_params)) data.custom_params = params;
      if (!Object.keys(data).length) return A.toast('Nothing changed');
      await A.busy(e.currentTarget, async () => {
        try {
          const r = await A.post('update_campaign', { campaign_id: c.id, name: c.name, data });
          A.toast('Campaign saved'); A.state.report = null; A.showError(null); A.route();
        } catch (err) { A.showError(err); }
      });
    };
  }
  const paramRow = p => `<div class="kv"><input class="k" placeholder="key (e.g. src)" value="${esc(p.key)}" style="max-width:200px"><input class="v" placeholder="value" value="${esc(p.value)}"><button type="button" class="btn icon sm" data-rm>${icon('x', 'sm')}</button></div>`;

  // ================= Ad groups =================
  async function adgroups(el, c) {
    el.innerHTML = loading;
    const d = await guard(A.get('adgroups', { campaign_id: c.id }), el);
    if (!d) return;
    const rows = d.adgroups.map(derive);
    const canAdd = ['SEARCH', 'DISPLAY', 'SHOPPING'].includes(c.type);
    el.innerHTML = `<section class="card"><div class="card-head"><h2>Ad groups <span class="pill">${rows.length}</span></h2>
      <div class="tools">${canAdd && APP.canEdit ? `<button class="btn primary" id="addAg">${icon('plus', 'sm')} Add ad group</button>` : ''}</div></div>
      ${!canAdd ? `<div class="alert info" style="margin:0 24px 16px">Ad groups cannot be created here for ${esc(label(c.type))} campaigns${c.type === 'PERFORMANCE_MAX' ? ' (PMax uses asset groups - see the "Ads & final URLs" tab)' : ''}.</div>` : ''}
      <div class="table-wrap" id="agT"></div></section>`;
    const t = new A.Table($('#agT'), [
      { k: 'name', label: 'Ad group', cls: 'l', html: r => `<b>${esc(r.name)}</b><span class="sub">${esc(label(r.type))}</span>` },
      { k: 'status', label: 'Status', cls: 'l', html: r => statusPill(r.status) },
      { k: 'cpc_bid', label: 'Default max CPC', html: r => r.cpc_bid ? money(r.cpc_bid, true) : '—' },
      ...A.metricCols(),
      { k: 'e', label: '', sort: false, html: r => APP.canEdit ? `<button class="btn sm" data-edit="${r.id}">${icon('edit', 'sm')} Edit</button>` : '' },
    ], { rows, sortKey: 'cost', empty: 'No ad groups', footer: rs => A.totalsRow(rs, 3, 1) });
    t.render();
    el.onclick = e => {
      if (e.target.closest('#addAg')) agModal(c, null, () => adgroups(el, c));
      const b = e.target.closest('[data-edit]');
      if (b) agModal(c, rows.find(r => r.id === b.dataset.edit), () => adgroups(el, c));
    };
  }

  function agModal(c, g, done) {
    const isNew = !g;
    g = g || { name: '', status: 'ENABLED', cpc_bid: '', tracking_url_template: '', final_url_suffix: '' };
    const m = A.modal({
      title: isNew ? 'New ad group' : 'Edit ad group: ' + esc(g.name),
      body: `<label class="f">Ad group name</label><input id="agName" value="${esc(g.name)}" placeholder="e.g. Wall Art - Exact">
        <div class="grid2"><div><label class="f">Status</label><select id="agSt"><option value="ENABLED">Active</option><option value="PAUSED" ${g.status === 'PAUSED' ? 'selected' : ''}>Paused</option></select></div>
        <div><label class="f">Default max CPC bid</label><input id="agBid" type="number" step="0.01" min="0" value="${g.cpc_bid || ''}" placeholder="e.g. 12.50"><div class="hint">Used with Manual CPC. Ignored by Smart Bidding.</div></div></div>
        <div class="section-t">Tracking (optional)</div>${trackingFields(g, 'ag')}
        ${isNew ? '<div class="hint" style="margin-top:12px">After creating the ad group, add keywords in the Keywords tab. For ads, use "New RSA ad" in the "Ads & final URLs" tab.</div>' : ''}`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="agSave">${isNew ? 'Create' : 'Save'}</button>`,
    });
    bindVt(m.el);
    m.$('#agSave').onclick = e => A.busy(e.currentTarget, async () => {
      const data = { name: m.$('#agName').value, status: m.$('#agSt').value, cpc_bid: m.$('#agBid').value,
                     tracking_url_template: m.$('#agtpl').value, final_url_suffix: m.$('#agsfx').value };
      try {
        if (isNew) await A.post('create_adgroup', { campaign_id: c.id, data });
        else await A.post('update_adgroup', { ag_id: g.id, name: g.name, data });
        m.close(); A.toast(isNew ? 'Ad group created' : 'Ad group saved'); done();
      } catch (err) { m.close(); A.showError(err); }
    });
  }

  // ================= Ads & final URLs =================
  async function ads(el, c) {
    el.innerHTML = loading;
    const d = await guard(A.get('ads', { campaign_id: c.id }), el);
    if (!d) return;
    const rows = d.ads.map(derive);
    el.innerHTML = `
      ${d.asset_groups.length ? `<section class="card"><div class="card-head"><h2>Asset groups (Performance Max) <span class="pill">${d.asset_groups.length}</span></h2></div>
        <div class="table-wrap" id="agrT"></div></section>` : ''}
      <section class="card"><div class="card-head"><h2>Ads <span class="pill">${rows.length}</span></h2>
        <div class="tools">${APP.canEdit && c.type === 'SEARCH' ? `<button class="btn primary" id="newRsa">${icon('plus', 'sm')} New RSA ad</button>` : ''}${APP.canEdit ? `<button class="btn" id="bulkUrl">${icon('edit', 'sm')} Find & replace URL in this campaign</button>` : ''}</div></div>
        <div class="table-wrap" id="adT"></div></section>`;
    if (d.asset_groups.length) {
      new A.Table($('#agrT'), [
        { k: 'name', label: 'Asset group', cls: 'l', html: r => `<b>${esc(r.name)}</b>` },
        { k: 'status', label: 'Status', cls: 'l', html: r => statusPill(r.status) },
        { k: 'u', label: 'Final URL', cls: 'l', sort: false, html: r => `<span class="url" title="${esc(r.final_urls.join('\n'))}">${esc(r.final_urls[0] || '—')}</span>` },
        { k: 'e', label: '', sort: false, html: r => APP.canEdit ? `<button class="btn sm" data-ag-edit="${r.id}">${icon('edit', 'sm')} Edit URL</button>` : '' },
      ], { rows: d.asset_groups }).render();
    }
    new A.Table($('#adT'), [
      { k: 'title', label: 'Ad', cls: 'l', html: r => `<b class="sub" style="color:var(--text);font-size:13px;max-width:320px" title="${esc(r.title)}">${esc(r.title)}</b><span class="sub">${esc(r.ag_name)} · ${esc(label(r.type))}</span>` },
      { k: 'status', label: 'Status', cls: 'l', html: r => statusPill(r.status) + (r.approval && r.approval !== 'APPROVED' ? `<span class="sub">${esc(label(r.approval))}</span>` : '') },
      { k: 'u', label: 'Final URL', cls: 'l', sort: false, html: r => `<span class="url" title="${esc(r.final_urls.join('\n'))}">${esc(r.final_urls[0] || '—')}</span>${r.tracking_url_template || r.final_url_suffix ? '<span class="sub">+ tracking</span>' : ''}` },
      { k: 'clicks', label: 'Clicks', html: r => num(r.clicks) },
      { k: 'ctr', label: 'CTR', html: r => dec(r.ctr) + '%' },
      { k: 'cost', label: 'Cost', html: r => money(r.cost) },
      { k: 'conv', label: 'Conv.', html: r => dec(r.conv) },
      { k: 'e', label: '', sort: false, html: r => APP.canEdit ? `<button class="btn sm" data-edit="${r.id}">${icon('edit', 'sm')} Edit</button>` : '' },
    ], { rows, sortKey: 'cost', empty: 'No ads in this campaign' }).render();

    el.onclick = e => {
      if (e.target.closest('#bulkUrl')) return A.bulkUrlModal(c.id, c.name, () => ads(el, c));
      if (e.target.closest('#newRsa')) {
        return A.get('adgroups', { campaign_id: c.id }).then(g => {
          const list = (g.adgroups || []).filter(x => x.status !== 'REMOVED');
          if (!list.length) return A.toast('Create an ad group in the "Ad groups" tab first');
          A.rsaModal(c, list, () => ads(el, c), { final_url: rows.find(r => r.final_urls?.length)?.final_urls[0] || '' });
        }).catch(A.showError);
      }
      const b = e.target.closest('[data-edit]');
      if (b) adModal(rows.find(r => r.id === b.dataset.edit), () => ads(el, c));
      const g = e.target.closest('[data-ag-edit]');
      if (g) assetGroupModal(d.asset_groups.find(r => r.id === g.dataset.agEdit), () => ads(el, c));
    };
  }

  function adModal(ad, done) {
    const m = A.modal({
      title: 'Ad edit', wide: true,
      body: `<p class="muted" style="margin-top:12px">${esc(ad.title)}<br><span class="small">${esc(ad.ag_name)} · Ad ID ${esc(ad.id)}</span></p>
        <label class="f">Final URL(s) <span class="muted">(one URL per line)</span></label>
        <textarea id="fu" rows="3">${esc(ad.final_urls.join('\n'))}</textarea>
        <div class="hint">Changing the final URL sends the ad back for review (can take a few hours).</div>
        <label class="f">Status</label><select id="st"><option value="ENABLED">Active</option><option value="PAUSED" ${ad.status === 'PAUSED' ? 'selected' : ''}>Paused</option></select>
        <div class="section-t">Ad-level tracking (optional)</div>${trackingFields(ad, 'ad')}`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="adSave">Save</button>`,
    });
    bindVt(m.el);
    m.$('#adSave').onclick = e => A.busy(e.currentTarget, async () => {
      const data = {};
      const urls = m.$('#fu').value.split('\n').map(s => s.trim()).filter(Boolean);
      if (urls.join('\n') !== ad.final_urls.join('\n')) data.final_urls = urls;
      if (m.$('#adtpl').value !== ad.tracking_url_template) data.tracking_url_template = m.$('#adtpl').value;
      if (m.$('#adsfx').value !== ad.final_url_suffix) data.final_url_suffix = m.$('#adsfx').value;
      if (m.$('#st').value !== ad.status) data.status = m.$('#st').value;
      if (!Object.keys(data).length) { m.close(); return A.toast('Nothing changed'); }
      try { await A.post('update_ad', { ag_id: ad.ag_id, ad_id: ad.id, data }); m.close(); A.toast('Ad saved'); done(); }
      catch (err) { m.close(); A.showError(err); }
    });
  }

  function assetGroupModal(g, done) {
    const m = A.modal({
      title: 'Asset group: ' + esc(g.name),
      body: `<label class="f">Final URL(s) <span class="muted">(one per line)</span></label><textarea id="fu" rows="3">${esc(g.final_urls.join('\n'))}</textarea>
        <label class="f">Status</label><select id="st"><option value="ENABLED">Active</option><option value="PAUSED" ${g.status === 'PAUSED' ? 'selected' : ''}>Paused</option></select>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">Save</button>`,
    });
    m.$('#sv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        await A.post('update_asset_group', { asset_group_id: g.id, name: g.name, data: { final_urls: m.$('#fu').value.split('\n').map(s => s.trim()).filter(Boolean), status: m.$('#st').value } });
        m.close(); A.toast('Asset group saved'); done();
      } catch (err) { m.close(); A.showError(err); }
    });
  }

  // ================= Keywords =================
  async function keywords(el, c) {
    el.innerHTML = loading;
    const [d, g] = await Promise.all([guard(A.get('keywords', { campaign_id: c.id }), el), A.get('adgroups', { campaign_id: c.id }).catch(() => ({ adgroups: [] }))]);
    if (!d) return;
    const rows = d.keywords.map(derive);
    const ags = g.adgroups || [];
    el.innerHTML = `<section class="card"><div class="card-head"><h2>Keywords <span class="pill">${rows.length}</span></h2>
      <div class="tools"><div class="search-in">${icon('search', 'sm')}<input id="kQ" placeholder="Filter keywords…"></div>
      ${APP.canEdit && ags.length && c.type === 'SEARCH' ? `<button class="btn primary" id="addKw">${icon('plus', 'sm')} Add keywords</button>` : ''}</div></div>
      <div class="table-wrap" id="kT"></div></section>`;
    const mt = { EXACT: '[', PHRASE: '"', BROAD: '' }, mte = { EXACT: ']', PHRASE: '"', BROAD: '' };
    const t = new A.Table($('#kT'), [
      { k: 'text', label: 'Keyword', cls: 'l', html: r => `<b>${esc(mt[r.match] + r.text + mte[r.match])}</b><span class="sub">${esc(r.ag_name)} · ${esc(label(r.match))}</span>` },
      { k: 'status', label: 'Status', cls: 'l', html: r => statusPill(r.status) },
      { k: 'qs', label: 'QS', html: r => r.qs ? `<span class="qs ${r.qs >= 7 ? 'hi' : r.qs <= 4 ? 'lo' : ''}">${r.qs}</span>` : '—' },
      { k: 'cpc_bid', label: 'Max CPC', html: r => r.cpc_bid ? money(r.cpc_bid, true) : '<span class="muted">ad group</span>' },
      { k: 'final_url', label: 'Final URL', cls: 'l', sort: false, html: r => r.final_url ? `<a href="${esc(r.final_url)}" target="_blank" rel="noopener" class="kw-url" title="${esc(r.final_url)}">${esc(r.final_url.replace(/^https?:\/\//, '').slice(0, 34))}${r.final_url.length > 41 ? '…' : ''}</a>` : '<span class="muted">ad\u2019s URL</span>' },
      ...A.metricCols(),
      { k: 'e', label: '', sort: false, html: r => APP.canEdit ? `<button class="btn sm" data-kw="${r.ag_id}~${r.id}">${icon('edit', 'sm')}</button>
          <button class="btn sm" data-kt="${r.ag_id}~${r.id}" title="${r.status === 'ENABLED' ? 'Pause' : 'Enable'}">${icon(r.status === 'ENABLED' ? 'pause' : 'play', 'sm')}</button>` : '' },
    ], { rows, sortKey: 'cost', empty: 'No keywords', filter: r => r.text.toLowerCase().includes($('#kQ').value.toLowerCase()), footer: rs => A.totalsRow(rs, 4, 1) });
    t.render();
    $('#kQ').oninput = () => t.render();
    const find = k => rows.find(r => r.ag_id + '~' + r.id === k);
    el.onclick = async e => {
      if (e.target.closest('#addKw')) return kwAddModal(ags, () => keywords(el, c));
      const tg = e.target.closest('[data-kt]');
      if (tg) {
        const r = find(tg.dataset.kt), next = r.status === 'ENABLED' ? 'PAUSED' : 'ENABLED';
        try { await A.post('update_keyword', { ag_id: r.ag_id, kw_id: r.id, name: r.text, data: { status: next } }); r.status = next; t.render(); A.toast('Keyword ' + (next === 'PAUSED' ? 'paused' : 'enabled') + ' ✓'); }
        catch (err) { A.showError(err); }
      }
      const ed = e.target.closest('[data-kw]');
      if (ed) {
        const r = find(ed.dataset.kw);
        const m = A.modal({
          title: 'Edit keyword: ' + esc(r.text),
          body: `<label class="f">Max CPC bid</label><input id="b" type="number" step="0.01" value="${r.cpc_bid || ''}">
            <div class="hint">Leave empty to use the ad group's default bid.</div>
            <label class="f" style="margin-top:14px">Final URL <span class="muted">(optional, overrides the ad's URL for this keyword)</span></label>
            <input id="ku" value="${esc(r.final_url || '')}" placeholder="https://www.example.com/landing-page">
            <div class="hint">Leave empty and clicks use the ad's final URL. Changing it sends the keyword back for review.</div>
            <details class="more" style="margin-top:12px"><summary>Keyword-level tracking (advanced)</summary>
              ${trackingFields(r, 'k')}
            </details>`,
          foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">Save</button>`,
        });
        bindVt(m.el);
        m.$('#sv').onclick = ev => A.busy(ev.currentTarget, async () => {
          const data = {};
          if (m.$('#b').value !== String(r.cpc_bid || '')) data.cpc_bid = m.$('#b').value;
          if (m.$('#ku').value.trim() !== (r.final_url || '')) data.final_url = m.$('#ku').value.trim();
          if (m.$('#ktpl').value.trim() !== (r.tracking_url_template || '')) data.tracking_url_template = m.$('#ktpl').value.trim();
          if (m.$('#ksfx').value.trim() !== (r.final_url_suffix || '')) data.final_url_suffix = m.$('#ksfx').value.trim();
          if (!Object.keys(data).length) { m.close(); return A.toast('Nothing changed'); }
          try {
            await A.post('update_keyword', { ag_id: r.ag_id, kw_id: r.id, name: r.text, data });
            Object.assign(r, { cpc_bid: +(data.cpc_bid ?? r.cpc_bid) || 0 }, 'final_url' in data ? { final_url: data.final_url } : {},
              'tracking_url_template' in data ? { tracking_url_template: data.tracking_url_template } : {},
              'final_url_suffix' in data ? { final_url_suffix: data.final_url_suffix } : {});
            m.close(); t.render(); A.toast('Keyword saved');
          } catch (err) { m.close(); A.showError(err); }
        });
      }
    };
  }

  function kwAddModal(ags, done) {
    const m = A.modal({
      title: 'Add keywords',
      body: `<label class="f">Ad group</label><select id="ag">${ags.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select>
        <label class="f">Keywords <span class="muted">(one per line)</span></label><textarea id="kw" rows="6" placeholder="wall art online&#10;home decor items&#10;table lamp"></textarea>
        <div class="grid2"><div><label class="f">Match type</label><select id="mt"><option value="PHRASE">Phrase "..."</option><option value="EXACT">Exact [...]</option><option value="BROAD">Broad</option></select></div>
        <div><label class="f">Max CPC <span class="muted">(optional)</span></label><input id="cpc" type="number" step="0.01"></div></div>
        <label class="f">Final URL <span class="muted">(optional, applied to all keywords above)</span></label>
        <input id="fu" placeholder="https://www.example.com/landing-page">
        <div class="hint">Leave empty and clicks use the ad's final URL.</div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">Add</button>`,
    });
    m.$('#sv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        const r = await A.post('add_keywords', { ag_id: m.$('#ag').value, keywords: m.$('#kw').value, match: m.$('#mt').value, cpc_bid: m.$('#cpc').value, final_url: m.$('#fu').value.trim() });
        m.close(); A.toast(`${r.added} keyword(s) added`); done();
      } catch (err) { m.close(); A.showError(err); }
    });
  }

  // ================= Search terms + negatives =================
  async function terms(el, c) {
    el.innerHTML = loading;
    const d = await guard(A.get('search_terms', { campaign_id: c.id }), el);
    if (!d) return;
    const rows = d.terms.map(derive);
    const sel = new Set();
    el.innerHTML = `<section class="card"><div class="card-head"><h2>Search terms <span class="pill">${rows.length}</span></h2>
        <div class="tools"><div class="search-in">${icon('search', 'sm')}<input id="sQ" placeholder="Filter…"></div>
        <label class="row small" style="gap:6px"><input type="checkbox" id="waste"> No conversions only</label></div></div>
        <div class="bulkbar hidden" id="sBulk"></div><div class="table-wrap" id="sT"></div></section>
      <section class="card"><div class="card-head"><h2>Negative keywords <span class="pill">${d.negatives.length}</span></h2>
        ${APP.canEdit ? `<button class="btn" id="addNeg">${icon('plus', 'sm')} Add negative</button>` : ''}</div>
        <div class="table-wrap" id="nT"></div></section>`;
    const t = new A.Table($('#sT'), [
      { k: 's', label: '', cls: 'c', sort: false, html: r => r.status === 'EXCLUDED' ? '' : `<input type="checkbox" data-s="${esc(r.term)}" ${sel.has(r.term) ? 'checked' : ''}>` },
      { k: 'term', label: 'Search term', cls: 'l', html: r => `<b>${esc(r.term)}</b><span class="sub">${esc(r.ag_name)}${r.status && r.status !== 'NONE' ? ' · ' + esc(label(r.status)) : ''}</span>` },
      ...A.metricCols(),
    ], { rows, sortKey: 'cost', empty: 'No search terms in this range (available for Search campaigns)',
         filter: r => r.term.toLowerCase().includes($('#sQ').value.toLowerCase()) && (!$('#waste').checked || (r.conv === 0 && r.cost > 0)),
         after: () => bar() });
    t.render();
    new A.Table($('#nT'), [
      { k: 'text', label: 'Negative keyword', cls: 'l', html: r => `<b>${esc(r.text)}</b>` },
      { k: 'match', label: 'Match', cls: 'l', html: r => esc(label(r.match)) },
      { k: 'x', label: '', sort: false, html: r => APP.canEdit ? `<button class="btn sm danger" data-rmneg="${r.id}">${icon('trash', 'sm')}</button>` : '' },
    ], { rows: d.negatives, empty: 'No negative keywords' }).render();

    function bar() {
      const b = $('#sBulk');
      if (!sel.size || !APP.canEdit) { b.classList.add('hidden'); return; }
      b.classList.remove('hidden');
      b.innerHTML = `${sel.size} selected <button class="btn sm" data-neg="EXACT">Negative [exact]</button><button class="btn sm" data-neg="PHRASE">Negative "phrase"</button><button class="btn sm ghost" data-neg="clear">Clear</button>`;
    }
    $('#sQ').oninput = () => t.render();
    $('#waste').onchange = () => t.render();
    el.onchange = e => { if (e.target.dataset.s) { e.target.checked ? sel.add(e.target.dataset.s) : sel.delete(e.target.dataset.s); bar(); } };
    el.onclick = async e => {
      const n = e.target.closest('[data-neg]');
      if (n) {
        if (n.dataset.neg === 'clear') { sel.clear(); t.render(); return; }
        if (!await A.confirmBox(`Add ${sel.size} search term(s) as <b>negative (${label(n.dataset.neg)})</b> to this campaign?`)) return;
        try { const r = await A.post('add_negatives', { campaign_id: c.id, terms: [...sel], match: n.dataset.neg }); A.toast(`${r.added} negative(s) added`); terms(el, c); }
        catch (err) { A.showError(err); }
      }
      if (e.target.closest('#addNeg')) {
        const m = A.modal({ title: 'Add negative keywords', body: `<label class="f">Keywords (one per line)</label><textarea id="nk" rows="6" placeholder="free&#10;jobs&#10;amazon"></textarea>
          <label class="f">Match type</label><select id="nm"><option value="PHRASE">Phrase</option><option value="EXACT">Exact</option><option value="BROAD">Broad</option></select>`,
          foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">Add</button>` });
        m.$('#sv').onclick = ev => A.busy(ev.currentTarget, async () => {
          try { const r = await A.post('add_negatives', { campaign_id: c.id, terms: m.$('#nk').value, match: m.$('#nm').value }); m.close(); A.toast(`${r.added} negative(s) added`); terms(el, c); }
          catch (err) { m.close(); A.showError(err); }
        });
      }
      const rm = e.target.closest('[data-rmneg]');
      if (rm) {
        const neg = d.negatives.find(x => x.id === rm.dataset.rmneg);
        if (!await A.confirmBox(`Remove negative "${esc(neg.text)}"?`, 'Remove', true)) return;
        try { await A.post('remove_negative', { campaign_id: c.id, neg_id: neg.id, name: neg.text }); A.toast('Removed'); terms(el, c); }
        catch (err) { A.showError(err); }
      }
    };
  }
})();
