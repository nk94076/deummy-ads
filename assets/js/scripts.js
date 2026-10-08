/* Scripts: generate a Google Ads script for a campaign (rotate URL suffix by clicks, or set tracking/suffix) */
(() => {
  const { $, esc, icon, state, num, money, statusPill } = A;
  const loading = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';

  A.views.scripts = {
    eyebrow: 'Scripts',
    async render(el) {
      el.innerHTML = loading;
      let r;
      try { r = await A.get('report'); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (r.blocked) { el.innerHTML = A.blockedHtml(r.blocked); return; }
      const camps = (r.campaigns || []).slice().sort((a, b) => (a.status === 'ENABLED' ? 0 : 1) - (b.status === 'ENABLED' ? 0 : 1) || a.name.localeCompare(b.name));
      const first = camps.find(c => c.status === 'ENABLED') || camps[0];
      const activeN = camps.filter(c => c.status === 'ENABLED').length;

      el.innerHTML = `
        <section class="card"><div class="card-head"><h2>${icon('code')} Google Ads script generator</h2>
          <span class="muted small">${esc(state.acc?.name || '')} · ${esc(A.fmtId(state.acc?.id))}</span></div>
          <div class="card-body">
            <label class="f">Select campaign</label>
            <select id="sc_c" style="max-width:520px">
              <option value="all">All active campaigns (${activeN})</option>
              ${camps.map(c => `<option value="${esc(c.id)}" ${first && c.id === first.id ? 'selected' : ''}>${esc(c.name)} — ${c.status === 'ENABLED' ? 'Active' : 'Paused'}</option>`).join('')}
            </select>
            <div class="hint" id="sc_info"></div>

            <div class="alert info" style="margin:16px 0 0">${icon('bulb', 'sm')} The script is built automatically from the selected campaign — it rotates the campaign's Final URL suffix as clicks come in, so clicks and tracking URLs change on their own. No URL or template to enter.</div>

            <label class="row f" style="gap:10px;margin-top:16px;align-items:center"><span class="switch"><input type="checkbox" id="sc_prot" checked><span></span></span>
              Protect script <span class="muted">(obfuscate so competitors can't copy the logic)</span></label>
            <div class="row" style="gap:10px;margin-top:14px"><button class="btn primary" id="sc_go">${icon('code', 'sm')} Generate script</button>
              <span class="muted small">Campaign-level URL changes don't send ads for review.</span></div>
          </div></section>
        <div id="sc_out"></div>`;

      const info = () => {
        const c = camps.find(x => x.id === $('#sc_c').value);
        $('#sc_info').innerHTML = c ? `${statusPill(c.status)} · Budget ${money(c.budget)}/day · ${num(c.clicks)} clicks in selected dates`
          : `${activeN} active campaign(s) will be included`;
      };
      info();
      $('#sc_c').onchange = info;
      $('#sc_go').onclick = e => A.busy(e.currentTarget, async () => {
        try {
          const d = await A.post('script_generate', {
            campaign_ids: [$('#sc_c').value], mode: 'ROTATE',
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
