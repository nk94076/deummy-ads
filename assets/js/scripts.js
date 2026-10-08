/* Scripts: generate a Google Ads script for a campaign (rotate URL suffix by clicks, or set tracking/suffix) */
(() => {
  const { $, esc, icon, state, num, money, statusPill } = A;
  const loading = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';


  /** Sample suffix list for a campaign (clickref per slot + standard UTM) */
  const sampleSuffixes = c => {
    const slug = String(c?.name || 'campaign').toLowerCase().replace(/^trivago\s*/, '').replace(/[^a-z0-9]+/g, '') || 'cmp';
    return [1, 2, 3, 4, 5].map(i => `clickref=th_${slug}_${String(i).padStart(2, '0')}&utm_source=google&utm_medium=cpc&utm_campaign={campaignid}`).join('\n');
  };

  A.views.scripts = {
    eyebrow: 'Scripts',
    async render(el) {
      el.innerHTML = loading;
      let r;
      try { r = await A.get('report'); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
      const camps = (r.campaigns || []).slice().sort((a, b) => (a.status === 'ENABLED' ? 0 : 1) - (b.status === 'ENABLED' ? 0 : 1) || a.name.localeCompare(b.name));
      const first = camps.find(c => c.status === 'ENABLED') || camps[0];

      el.innerHTML = `
        <section class="card"><div class="card-head"><h2>${icon('code')} Google Ads script generator</h2>
          <span class="muted small">${esc(state.acc?.name || '')} · ${esc(A.fmtId(state.acc?.id))}</span></div>
          <div class="card-body">
            <div class="grid2" style="gap:16px">
              <div><label class="f">Campaign</label>
                <select id="sc_c">
                  <option value="all">All active campaigns (${camps.filter(c => c.status === 'ENABLED').length})</option>
                  ${camps.map(c => `<option value="${esc(c.id)}" ${first && c.id === first.id ? 'selected' : ''}>${esc(c.name)} — ${c.status === 'ENABLED' ? 'Active' : 'Paused'}</option>`).join('')}
                </select>
                <div class="hint" id="sc_info"></div></div>
              <div><label class="f">Script type</label>
                <select id="sc_m">
                  <option value="ROTATE">Clicks-based URL rotation (Final URL suffix)</option>
                  <option value="SET">Set tracking template / Final URL suffix</option>
                </select>
                <div class="hint" id="sc_mh"></div></div>
            </div>

            <div id="sc_rot">
              <label class="f">URL suffixes <span class="muted">(one per line — or paste full URLs, the part after "?" is used)</span></label>
              <textarea id="sc_sfx" rows="6" spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">${esc(sampleSuffixes(first))}</textarea>
              <div class="grid2" style="gap:16px">
                <div><label class="f">Switch to the next suffix every</label>
                  <div class="row" style="gap:8px;align-items:center"><input id="sc_n" type="number" min="1" value="50" style="max-width:140px"><span class="muted">clicks (counted per day)</span></div></div>
                <div><label class="f">Run report email <span class="muted">(optional)</span></label><input id="sc_em" type="email" placeholder="pankaj@clickorbits.com"></div>
              </div>
            </div>

            <div id="sc_set" class="hidden">
              <label class="f">Tracking template <span class="muted">(optional)</span></label>
              <input id="sc_tpl" spellcheck="false" placeholder="{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}">
              ${A.vtChips('sc_tpl')}
              <label class="f">Final URL suffix <span class="muted">(optional, no leading ?)</span></label>
              <input id="sc_fs" spellcheck="false" placeholder="clickref={campaignid}&utm_term={keyword}">
              ${A.vtChips('sc_fs')}
              <label class="f">Run report email <span class="muted">(optional)</span></label><input id="sc_em2" type="email" placeholder="pankaj@clickorbits.com" style="max-width:420px">
            </div>

            <label class="row f" style="gap:10px;margin-top:16px;align-items:center"><span class="switch"><input type="checkbox" id="sc_prot" checked><span></span></span>
              Protect script <span class="muted">(obfuscate so competitors can't copy the logic)</span></label>
            <div class="row" style="gap:10px;margin-top:14px"><button class="btn primary" id="sc_go">${icon('code', 'sm')} Generate script</button>
              <span class="muted small">Campaign-level URL changes don't send ads for review.</span></div>
          </div></section>
        <div id="sc_out"></div>`;
      A.bindVt(el);

      const info = () => {
        const v = $('#sc_c').value;
        const c = camps.find(x => x.id === v);
        $('#sc_info').innerHTML = c ? `${statusPill(c.status)} · Budget ${money(c.budget)}/day · ${num(c.clicks)} clicks in selected dates`
          : `${camps.filter(c => c.status === 'ENABLED').length} active campaign(s) will be included`;
      };
      const modeUi = () => {
        const rot = $('#sc_m').value === 'ROTATE';
        $('#sc_rot').classList.toggle('hidden', !rot);
        $('#sc_set').classList.toggle('hidden', rot);
        $('#sc_mh').textContent = rot ? 'Every N clicks the campaign moves to the next suffix; schedule the script hourly.'
          : 'Applies the template / suffix to the campaign once (run again any time).';
      };
      info(); modeUi();
      $('#sc_c').onchange = () => {
        info();
        const c = camps.find(x => x.id === $('#sc_c').value);
        if (c && /clickref=th_/.test($('#sc_sfx').value)) $('#sc_sfx').value = sampleSuffixes(c);
      };
      $('#sc_m').onchange = modeUi;
      $('#sc_go').onclick = e => A.busy(e.currentTarget, async () => {
        const rot = $('#sc_m').value === 'ROTATE';
        try {
          const d = await A.post('script_generate', {
            campaign_ids: [$('#sc_c').value], mode: $('#sc_m').value,
            suffixes: $('#sc_sfx').value, clicks_per: $('#sc_n').value,
            tracking_template: $('#sc_tpl').value, final_url_suffix: $('#sc_fs').value,
            email: rot ? $('#sc_em').value : $('#sc_em2').value,
            protect: $('#sc_prot').checked ? '1' : '0',
          });
          A.showError(null); output(d);
        } catch (err) { A.showError(err); }
      });
    },
  };

  function output(d) {
    const out = $('#sc_out');
    out.innerHTML = `<section class="card"><div class="card-head"><h2>${icon('check')} Script ready <span class="pill">${esc(d.build)}</span>${d.protected ? ' <span class="pill" style="background:var(--good-bg);color:var(--good)">Protected</span>' : ''}</h2>
        <div class="tools"><button class="btn" id="sc_copy">${icon('copy', 'sm')} Copy</button><button class="btn primary" id="sc_dl">${icon('dl', 'sm')} Download .js</button></div></div>
      <div class="card-body">
        <div class="muted small" style="margin-bottom:10px">${d.mode === 'ROTATE' ? `Clicks-based rotation · ${d.suffixes} suffixes` : 'Tracking / suffix update'} · ${d.campaigns} campaign(s) · generated ${esc(d.generated)}</div>
        <pre class="example script-out" id="sc_code">${esc(d.script)}</pre>
        <div class="section-t">Install in Google Ads</div>
        <ol class="small" style="margin:8px 0 0;padding-left:18px;line-height:1.9">
          <li>Google Ads → <b>Tools</b> → <b>Bulk actions</b> → <b>Scripts</b> → <b>+ New script</b></li>
          <li>Delete the sample code, paste this script, give it a name</li>
          <li><b>Authorize</b> → <b>Preview</b> (logs show what would change) → <b>Save</b></li>
          <li>${d.mode === 'ROTATE' ? 'Set <b>Frequency: Hourly</b> so the suffix follows the clicks' : 'Click <b>Run</b> once (or schedule it)'}</li>
        </ol>
      </div></section>`;
    $('#sc_copy').onclick = () => { navigator.clipboard?.writeText(d.script); A.toast('Script copied'); };
    $('#sc_dl').onclick = () => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([d.script], { type: 'text/javascript' }));
      a.download = d.filename; a.click(); URL.revokeObjectURL(a.href);
    };
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
})();
