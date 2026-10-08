/* Tools: account URL settings, bulk tracking, bulk final URL replace, change log */
(() => {
  const { $, esc, icon, state } = A;

  /** Bulk final URL find & replace (whole account or one campaign) */
  A.bulkUrlModal = (campId, campName, done) => {
    const m = A.modal({
      title: 'Final URL find & replace', wide: true,
      body: `<p class="muted" style="margin-top:12px">Replace text in the final URLs of all ads and PMax asset groups ${campId ? `in campaign <b>${esc(campName)}</b>` : 'in the <b>whole account</b>'}. Preview first, then Apply.</p>
        <div class="grid2"><div><label class="f">Find</label><input id="find" placeholder="affid=oldid"></div>
        <div><label class="f">Replace with</label><input id="rep" placeholder="affid=newid"></div></div>
        <div class="hint">Examples: change domain (old.com → new.com), change affiliate ID, http → https. Empty "Replace" removes the text.</div>
        <div id="prev" style="margin-top:16px"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn" id="pv">${icon('eye', 'sm')} Preview</button><button class="btn primary" id="ap" disabled>Apply</button>`,
    });
    let last = null;
    const run = async apply => {
      const find = m.$('#find').value, rep = m.$('#rep').value;
      if (!find) return A.toast('Enter text in "Find"');
      return A.post('bulk_url', { find, replace: rep, campaign_id: campId || '', apply });
    };
    m.$('#pv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        const r = await run(false); if (!r) return;
        last = m.$('#find').value + '|' + m.$('#rep').value;
        m.$('#prev').innerHTML = r.count ? `<div class="alert info"><b>${r.count}</b> item(s) will change${r.count > r.preview.length ? ` (showing first ${r.preview.length})` : ''}.</div>
          <div class="table-wrap" style="max-height:320px;overflow:auto"><table class="data"><thead><tr><th class="l nosort">Campaign</th><th class="l nosort">Type</th><th class="l nosort">URL change</th></tr></thead><tbody>
          ${r.preview.map(c => `<tr><td class="l">${esc(c.campaign)}</td><td class="l">${c.kind === 'ad' ? 'Ad' : 'Asset group'}</td><td class="l diff">${c.urls.map((u, i) => `<div class="o">${esc(u)}</div><div class="n">${esc(c.new[i])}</div>`).join('')}</td></tr>`).join('')}</tbody></table></div>`
          : '<div class="alert warn">This text was not found in any final URL.</div>';
        m.$('#ap').disabled = !r.count || !APP.canEdit;
      } catch (err) { m.$('#prev').innerHTML = `<div class="alert err">${esc(err.error || err)}</div>`; }
    });
    m.$('#ap').onclick = async e => {
      const btn = e.currentTarget; // currentTarget becomes null after await
      if (last !== m.$('#find').value + '|' + m.$('#rep').value) return A.toast('Text changed - preview again first');
      if (!await A.confirmBox('Update final URLs? Google will re-review the changed ads.')) return;
      A.busy(btn, async () => {
        try {
          const r = await run(true);
          m.close();
          A.toast(`${r.applied} URL(s) updated${r.failed ? `, ${r.failed} failed` : ''}`);
          if (r.failed) A.showError({ error: `${r.failed} item(s) not updated`, hint: r.errors.join(' | ') });
          done && done();
        } catch (err) { m.close(); A.showError(err); }
      });
    };
  };

  /** Tracking template / suffix - selected campaigns (or all) */
  A.trackingModal = (ids = []) => {
    const m = A.modal({
      title: 'Tracking template / Final URL suffix', wide: true,
      body: `<p class="muted" style="margin-top:12px">Applies to ${ids.length ? `<b>${ids.length}</b> selected campaign(s)` : '<b>all campaigns</b>'}. Only the fields you tick will change.</p>
        <label class="row f" style="gap:8px"><input type="checkbox" id="uT" checked> Update tracking template</label>
        <input id="btpl" placeholder="{lpurl}?utm_source=google&utm_campaign={campaignid}">${A.vtChips('btpl')}
        <label class="row f" style="gap:8px"><input type="checkbox" id="uS" checked> Update final URL suffix</label>
        <input id="bsfx" placeholder="utm_source=google&utm_medium=cpc&gclid={gclid}">${A.vtChips('bsfx')}
        <div class="hint">Ticking a field but leaving it empty <b>clears</b> that value. For account level, use Tools > Account URL settings.</div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">Apply</button>`,
    });
    A.bindVt(m.el);
    m.$('#sv').onclick = async e => {
      const data = {};
      if (m.$('#uT').checked) data.tracking_url_template = m.$('#btpl').value;
      if (m.$('#uS').checked) data.final_url_suffix = m.$('#bsfx').value;
      if (!Object.keys(data).length) return A.toast('Tick at least one field');
      if (ids.length) data.campaign_ids = ids;
      A.busy(e.currentTarget, async () => {
        try { const r = await A.post('bulk_tracking', { scope: 'campaigns', data }); m.close(); A.toast(`${r.updated} campaign(s) updated`); state.report = null; }
        catch (err) { m.close(); A.showError(err); }
      });
    };
  };

  A.views.tools = {
    eyebrow: 'Tools', dates: false,
    async render(el) {
      el.innerHTML = `<div class="tool-grid">
        <section class="card"><div class="card-head"><h2>${icon('globe')} Account URL settings</h2></div><div class="form-card" id="acc"><span class="spin"></span></div></section>
        <section class="card"><div class="card-head"><h2>${icon('link')} Bulk tools</h2></div><div class="form-card">
          <div class="list-item" style="padding:14px 0;border:0"><div class="grow"><div class="t">Tracking template / suffix on all campaigns</div><div class="muted small">Change tracking on all campaigns at once (or select a few on the Campaigns page).</div></div><button class="btn" id="bt">Open</button></div>
          <div class="list-item" style="padding:14px 0"><div class="grow"><div class="t">Final URL find & replace</div><div class="muted small">Change domain / affiliate ID / parameter across the account's ads and PMax asset groups. With preview.</div></div><button class="btn" id="bu">Open</button></div>
          <div class="list-item" style="padding:14px 0"><div class="grow"><div class="t">Automation rules</div><div class="muted small">E.g. if cost > ₹2000 and 0 conversions in 7 days, pause the campaign.</div></div><a class="btn" href="#automation">Open</a></div>
        </div></section></div>
        <section class="card" style="margin-top:20px"><div class="card-head"><h2>${icon('line')} Change log</h2><span class="muted small">Your changes made from this tool</span></div><div id="log"><p style="padding:24px;text-align:center"><span class="spin"></span></p></div></section>`;
      $('#bt').onclick = () => A.trackingModal([]);
      $('#bu').onclick = () => A.bulkUrlModal(null, '', null);
      A.api('changes').then(d => {
        if (!$('#log')) return;
        $('#log').innerHTML = d.changes.length ? d.changes.map(c => `<div class="log-row"><span class="t">${esc(c.t)}</span><span class="muted">${esc(A.fmtId(c.cid))}</span><span style="flex:1">${esc(c.what)}${c.demo && A.state.demoLabels ? ' <span class="badge">demo</span>' : ''}</span></div>`).join('')
          : '<p class="muted" style="padding:0 24px 20px">No changes yet.</p>';
      }).catch(A.showError);
      try {
        const d = await A.get('account_settings');
        if (!$('#acc')) return;
        if (d.blocked) { $('#acc').innerHTML = A.blockedHtml(d.blocked); return; }
        const s = d.settings;
        $('#acc').innerHTML = `<p class="muted" style="margin-top:0">Applies to <b>all</b> ads in <b>${esc(s.name || state.acc.name)}</b> (unless set differently at campaign/ad group/ad level).</p>
          <label class="f">Tracking template</label><input id="atpl" value="${esc(s.tracking_url_template)}" placeholder="{lpurl}?utm_source=google">${A.vtChips('atpl')}
          <label class="f">Final URL suffix</label><input id="asfx" value="${esc(s.final_url_suffix)}" placeholder="utm_source=google&utm_medium=cpc">${A.vtChips('asfx')}
          <label class="row f" style="gap:10px"><span class="switch"><input type="checkbox" id="auto" ${s.auto_tagging ? 'checked' : ''}><span></span></span> Auto-tagging (gclid) ON</label>
          <div class="hint">Keep auto-tagging ON for GA4 / conversion tracking.</div>
          <div class="save-bar"><button class="btn primary" id="asv" ${APP.canEdit ? '' : 'disabled'}>Save</button></div>`;
        A.bindVt($('#acc'));
        $('#asv').onclick = e => A.busy(e.currentTarget, async () => {
          const data = {};
          if ($('#atpl').value !== s.tracking_url_template) data.tracking_url_template = $('#atpl').value;
          if ($('#asfx').value !== s.final_url_suffix) data.final_url_suffix = $('#asfx').value;
          if ($('#auto').checked !== s.auto_tagging) data.auto_tagging = $('#auto').checked;
          if (!Object.keys(data).length) return A.toast('Nothing changed');
          try { await A.post('update_account_settings', { data }); A.toast('Account settings saved'); A.route(); }
          catch (err) { A.showError(err); }
        });
      } catch (e) { A.showError(e); $('#acc').innerHTML = ''; }
    },
  };
})();
