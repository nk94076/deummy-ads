/* Create multiple Google Ads accounts under a manager account (MCC) at once */
(() => {
  const { $, $$, esc, icon, state } = A;

  A.views['new-accounts'] = {
    eyebrow: 'Accounts', dates: false, needsAccount: false,
    async render(el) {
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      let d;
      try { d = await A.api('mcc_list'); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      const cur0 = (state.acc?.currency && d.currencies.includes(state.acc.currency)) ? state.acc.currency : 'INR';
      const opt = (list, sel) => list.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
      if (!d.mccs.length) {
        el.innerHTML = `<section class="card status-panel"><div class="sp">${icon('db')}</div><h2>No manager account found</h2>
          <p class="muted">To create new accounts, connect a Google account that has access to a <b>Manager (MCC) account</b>.
          Shared accounts cannot create new accounts.</p><a class="btn primary" href="connect.php" style="margin-top:12px">${icon('link', 'sm')} Connect Google account</a></section>`;
        return;
      }
      el.innerHTML = `<div id="mcx">
        <div class="wz-head"><div><div class="crumbs"><a href="#overview">Accounts</a> › <b>Create new accounts</b></div>
          <h1 class="wz-title">Create new accounts in a manager account</h1>
          <div class="muted small">Up to ${d.max} at once. All accounts are created under the selected manager account.</div></div>
          <a class="btn" href="#link-accounts">${icon('link', 'sm')} Link existing accounts</a></div>

        <div class="alert warn"><b>Note:</b> after creation, <b>currency and time zone can never be changed</b>.
          You must add <b>billing (a payment method)</b> to each new account in Google Ads; until then it shows as "Inactive".
          Google also limits how many accounts a new or low-spend manager account can create.</div>

        <section class="card"><div class="form-card">
          <div class="sec-h" style="margin-top:18px"><span class="sec-n">1</span><div><h3>Manager account and defaults</h3><p>These apply to every account (you can override them per row).</p></div></div>
          <div class="grid2">
            <div><label class="f">Manager account (MCC)</label><select id="m_mcc">${d.mccs.map(m => `<option value="${esc(m.conn)}|${esc(m.id)}">${esc(m.name)} · ${esc(A.fmtId(m.id))} (${esc(m.email)})</option>`).join('')}</select></div>
            <div class="grid2" style="gap:0 12px"><div><label class="f">Currency</label><select id="m_cur">${opt(d.currencies, cur0)}</select></div>
              <div><label class="f">Time zone</label><select id="m_tz">${opt(d.timezones, 'Asia/Kolkata')}</select></div></div>
          </div>
          <details class="more"><summary>Optional: invite email, tracking template, final URL suffix</summary>
            <div class="grid2">
              <div><label class="f">Invite email <span class="muted">(gives this email access to each account)</span></label><input id="m_em" type="email" placeholder="client@gmail.com"></div>
              <div><label class="f">Access level</label><select id="m_role"><option value="ADMIN">Admin</option><option value="STANDARD">Standard</option><option value="READ_ONLY">Read only</option></select></div>
              <div><label class="f">Tracking template</label><input id="m_tpl" placeholder="{lpurl}?utm_source=google"></div>
              <div><label class="f">Final URL suffix</label><input id="m_sfx" placeholder="utm_source=google&utm_medium=cpc"></div>
            </div></details>
        </div></section>

        <section class="card"><div class="form-card">
          <div class="sec-h" style="margin-top:18px"><span class="sec-n">2</span><div><h3>List of accounts</h3><p>Generate from a name pattern, paste a list, or type them one by one.</p></div></div>
          <div class="gen-row">
            <div><label class="f">Name pattern <span class="muted">({n} = number)</span></label><input id="g_pat" value="Lyca US {n}"></div>
            <div><label class="f">How many</label><input id="g_cnt" type="number" min="1" max="${d.max}" value="5"></div>
            <div><label class="f">Start number</label><input id="g_start" type="number" min="0" value="1"></div>
            <div><button class="btn" id="g_go">${icon('plus', 'sm')} Generate</button></div>
          </div>
          <details class="more"><summary>Paste a list (from Excel)</summary>
            <textarea id="p_txt" rows="5" placeholder="One account per line:&#10;Lyca US 1&#10;Lyca US 2, USD, America/New_York&#10;Client ABC, INR, Asia/Kolkata, client@gmail.com"></textarea>
            <div class="hint">Format: <code>name, currency, time zone, invite email</code> — name alone works too (rest from defaults).</div>
            <button class="btn sm" id="p_go" style="margin-top:8px">Add to list</button></details>
          <div class="table-wrap mc-t"><table class="data"><thead><tr><th>#</th><th class="l">Account name</th><th class="l">Currency</th><th class="l">Time zone</th><th class="l">Invite email</th><th></th></tr></thead><tbody id="m_rows"></tbody></table></div>
          <div class="row" style="justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:8px"><button class="btn sm" id="m_add">${icon('plus', 'sm')} Add row</button><span class="muted small" id="m_cnt"></span></div>
        </div></section>

        <div class="wiz-bar"><div class="wb-l"><span class="muted small" id="m_sum"></span></div>
          <div class="wb-r"><button class="btn" id="m_check">${icon('shield', 'sm')}<span class="hide-sm"> Validate with Google</span></button>
          <button class="btn primary" id="m_create">${icon('plus', 'sm')} Create accounts</button></div></div>
        <div id="m_res"></div>
      </div>`;
      const root = $('#mcx');
      const R = $('#m_rows');
      const row = (r = {}) => `<tr><td class="c muted rn"></td><td class="l"><input class="r-name" value="${esc(r.name || '')}" placeholder="Account name"></td>
        <td class="l"><select class="r-cur"><option value="">Default</option>${opt(d.currencies, r.currency || '')}</select></td>
        <td class="l"><select class="r-tz"><option value="">Default</option>${opt(d.timezones, r.timezone || '')}</select></td>
        <td class="l"><input class="r-em" value="${esc(r.email || '')}" placeholder="(optional)"></td>
        <td><button type="button" class="btn icon sm ghost" data-rm title="Remove">${icon('x', 'sm')}</button></td></tr>`;
      const renum = () => {
        $$('tr', R).forEach((tr, i) => { tr.querySelector('.rn').textContent = i + 1; });
        const n = rows().length;
        $('#m_cnt').textContent = `${n} account(s)${n > d.max ? ` — max ${d.max}!` : ''}`;
        $('#m_create').innerHTML = `${icon('plus', 'sm')} Create ${n || ''} accounts`;
        const [, mid] = $('#m_mcc').value.split('|');
        $('#m_sum').textContent = `MCC ${A.fmtId(mid)} · default ${$('#m_cur').value} · ${$('#m_tz').value}`;
      };
      const rows = () => $$('tr', R).map(tr => ({ name: tr.querySelector('.r-name').value.trim(), currency: tr.querySelector('.r-cur').value,
        timezone: tr.querySelector('.r-tz').value, email: tr.querySelector('.r-em').value.trim() })).filter(r => r.name);
      const setRows = list => { R.innerHTML = list.map(row).join(''); renum(); };
      setRows([{}, {}, {}]);

      root.addEventListener('input', renum);
      root.addEventListener('change', renum);
      root.addEventListener('click', e => { const x = e.target.closest('[data-rm]'); if (x) { x.closest('tr').remove(); renum(); } });
      $('#m_add').onclick = () => { R.insertAdjacentHTML('beforeend', row()); renum(); R.lastElementChild.querySelector('input').focus(); };
      $('#g_go').onclick = () => {
        const n = Math.min(d.max, Math.max(1, +$('#g_cnt').value || 1)), s = +$('#g_start').value || 0, pat = $('#g_pat').value || 'Account {n}';
        const keep = rows();
        setRows([...keep, ...Array.from({ length: n }, (_, i) => ({ name: pat.includes('{n}') ? pat.replace(/\{n\}/g, s + i) : `${pat} ${s + i}` }))].slice(0, d.max));
      };
      $('#p_go').onclick = () => {
        const list = $('#p_txt').value.split('\n').map(l => l.split(/\t|,/).map(x => x.trim())).filter(x => x[0])
          .map(([name, currency = '', timezone = '', email = '']) => ({ name, currency: d.currencies.includes(currency.toUpperCase()) ? currency.toUpperCase() : '', timezone: d.timezones.includes(timezone) ? timezone : '', email }));
        setRows([...rows(), ...list].slice(0, d.max));
        if (list.length) A.toast(`${list.length} account(s) added to the list`);
      };

      const body = vo => {
        const [conn, mcc] = $('#m_mcc').value.split('|');
        return { conn, mcc, validate_only: vo, rows: rows(),
          defaults: { currency: $('#m_cur').value, timezone: $('#m_tz').value, email: $('#m_em').value.trim(), role: $('#m_role').value,
                      tracking_url_template: $('#m_tpl').value.trim(), final_url_suffix: $('#m_sfx').value.trim() } };
      };
      const showRes = r => {
        $('#m_res').innerHTML = `<section class="card"><div class="card-head"><h2>${icon(r.failed ? 'alert' : 'check')} ${r.validated ? 'Check result' : 'Result'}
            <span class="pill">${r.validated ? r.results.filter(x => x.ok).length + ' ok' : r.created + ' created'}</span>${r.failed ? `<span class="pill bad">${r.failed} failed</span>` : ''}</h2>
          ${!r.validated && r.created ? `<div class="tools"><button class="btn" id="r_csv">${icon('dl', 'sm')} CSV</button><button class="btn primary" id="r_ref">${icon('refresh', 'sm')} Account list refresh</button></div>` : ''}</div>
          <div class="table-wrap"><table class="data"><thead><tr><th>#</th><th class="l">Name</th><th class="l">Status</th><th class="l">Customer ID</th><th class="l">Currency / TZ</th></tr></thead><tbody>
          ${r.results.map(x => `<tr><td class="c">${x.row}</td><td class="l"><b>${esc(x.name)}</b>${x.email ? `<span class="sub">invite: ${esc(x.email)}</span>` : ''}</td>
            <td class="l">${x.ok ? `<span class="status st-ENABLED">${r.validated ? 'OK' : 'Created'}</span>` : `<span class="t-bad small">${esc(x.error)}</span>`}</td>
            <td class="l">${x.customer_id ? `<b>${esc(A.fmtId(x.customer_id))}</b>` : '—'}</td><td class="l small">${esc(x.currency || '')} ${x.timezone ? '· ' + esc(x.timezone) : ''}</td></tr>`).join('')}
          </tbody></table></div>
          ${!r.validated && r.created ? '<div class="card-body"><div class="alert info" style="margin:0">Next step: open Google Ads for each new account and add <b>Billing → Payment method</b>. The account then becomes "Active" and you can create campaigns here.</div></div>' : ''}
          </section>`;
        $('#m_res').scrollIntoView({ behavior: 'smooth', block: 'start' });
        $('#r_csv') && ($('#r_csv').onclick = () => A.csvDownload('new_accounts.csv', ['Name', 'Customer ID', 'Currency', 'Time zone', 'Status'],
          r.results.map(x => [x.name, x.customer_id ? A.fmtId(x.customer_id) : '', x.currency || '', x.timezone || '', x.ok ? 'Created' : x.error])));
        $('#r_ref') && ($('#r_ref').onclick = ev => A.busy(ev.currentTarget, async () => { await A.loadAccounts(true); A.toast('Account list refreshed'); }));
      };
      $('#m_check').onclick = e => A.busy(e.currentTarget, async () => {
        try { const r = await A.api('mcc_create', {}, body(true)); A.showError(null); showRes(r); } catch (err) { A.showError(err); }
      });
      $('#m_create').onclick = async e => {
        const btn = e.currentTarget; // currentTarget becomes null after await
        const b = body(false);
        if (!b.rows.length) return A.toast('Enter account names first');
        if (b.rows.length > d.max) return A.toast(`Maximum ${d.max} at a time`);
        const mccName = $('#m_mcc').selectedOptions[0].textContent;
        if (!await A.confirmBox(`Create <b>${b.rows.length}</b> new account(s)?<br><span class="muted small">${esc(mccName)}<br>Default: ${esc(b.defaults.currency)} · ${esc(b.defaults.timezone)} — cannot be changed later.</span>`, 'Create')) return;
        await A.busy(btn, async () => {
          try {
            const r = await A.api('mcc_create', {}, b); A.showError(null); showRes(r);
            if (r.created) {
              A.toast(`${r.created} account(s) created`);
              // remove created rows so a second click doesn't duplicate them
              const done = new Set(r.results.filter(x => x.ok).map(x => x.name));
              $$('tr', R).forEach(tr => { if (done.has(tr.querySelector('.r-name').value.trim())) tr.remove(); });
              renum();
            }
          }
          catch (err) { A.showError(err); }
        });
      };
    },
  };

  // ---- Link EXISTING accounts to a manager account, and unlink them ----
  A.views['link-accounts'] = {
    eyebrow: 'Accounts', dates: false, needsAccount: false,
    async render(el) {
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      let d;
      try { d = await A.api('mcc_list'); } catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (!d.mccs.length) {
        el.innerHTML = `<section class="card status-panel"><div class="sp">${icon('db')}</div><h2>No manager account found</h2>
          <p class="muted">Connect a Google account that has a <b>Manager (MCC) account</b> to link or unlink accounts.</p>
          <a class="btn primary" href="connect.php" style="margin-top:12px">${icon('link', 'sm')} Connect Google account</a></section>`;
        return;
      }
      el.innerHTML = `<div id="lkx">
        <div class="wz-head"><div><div class="crumbs"><a href="#overview">Accounts</a> › <b>Link existing accounts</b></div>
          <h1 class="wz-title">Link existing accounts to a manager account</h1>
          <div class="muted small">Add accounts that already exist to your MCC. This is the way in when a new manager account can't create accounts yet (the $1,000-spend rule).</div></div>
          <a class="btn" href="#new-accounts">${icon('plus', 'sm')} Create new instead</a></div>

        <section class="card"><div class="form-card">
          <label class="f">Manager account (MCC)</label>
          <select id="lk_mcc" style="max-width:520px">${d.mccs.map(m => `<option value="${esc(m.conn)}|${esc(m.id)}">${esc(m.name)} · ${esc(A.fmtId(m.id))} (${esc(m.email)})</option>`).join('')}</select>

          <div class="sec-h" style="margin-top:22px"><span class="sec-n">1</span><div><h3>Link accounts</h3><p>Paste the Customer IDs to link (one per line). Up to 50 at a time.</p></div></div>
          <textarea id="lk_ids" rows="5" placeholder="123-456-7890&#10;234 567 8901&#10;3456789012"></textarea>
          <div class="hint">Dashes and spaces are fine. Each account's owner must accept the invitation — unless you turn on auto-accept below.</div>
          <label class="row f" style="gap:10px;margin-top:12px"><span class="switch"><input type="checkbox" id="lk_auto" checked><span></span></span>
            Auto-accept where this tool already has access to the account</label>
          <div class="alert info" style="margin-top:8px"><b>How accepting works:</b> the manager sends an invitation and Google notifies the account.
            If this tool is already connected to that account (you manage it here), auto-accept links it instantly.
            Otherwise the account owner accepts it in their Google Ads → <i>Admin → Account access → Managers</i>.</div>
          <div class="save-bar" style="justify-content:flex-start;margin-top:12px"><button class="btn primary" id="lk_go">${icon('link', 'sm')} Link accounts</button></div>
          <div id="lk_res" style="margin-top:12px"></div>
        </div></section>

        <section class="card"><div class="card-head"><h2>${icon('db')} Currently linked accounts</h2>
          <button class="btn sm" id="lk_reload">${icon('refresh', 'sm')} Refresh</button></div>
          <div class="table-wrap" id="lk_list"><p style="padding:20px;text-align:center"><span class="spin"></span></p></div></section>
      </div>`;

      const mccVal = () => $('#lk_mcc').value.split('|'); // [conn, mccId]
      const statusPill = s => s === 'ACTIVE'
        ? '<span class="status st-ENABLED">Linked</span>'
        : (s === 'PENDING' ? '<span class="status st-PAUSED">Pending accept</span>' : `<span class="status">${esc(s)}</span>`);

      const loadList = async () => {
        const box = $('#lk_list'); if (!box) return;
        box.innerHTML = '<p style="padding:20px;text-align:center"><span class="spin"></span></p>';
        const [conn, mcc] = mccVal();
        let r;
        try { r = await A.api('mcc_clients', { conn, mcc }); }
        catch (e) { box.innerHTML = `<div class="card-body"><div class="alert err" style="margin:0">${esc(e.error || 'Could not load linked accounts.')}</div></div>`; return; }
        if (!r.clients.length) { box.innerHTML = '<div class="card-body"><p class="muted" style="margin:0">No linked accounts yet.</p></div>'; return; }
        box.innerHTML = `<table class="data"><thead><tr><th class="l">Account</th><th class="l">Customer ID</th><th class="l">Status</th><th></th></tr></thead><tbody>
          ${r.clients.map(c => `<tr><td class="l"><b>${esc(c.name || '(no name)')}</b>${c.currency ? `<span class="sub">${esc(c.currency)}</span>` : ''}</td>
            <td class="l">${esc(A.fmtId(c.customer_id))}</td><td class="l">${statusPill(c.status)}</td>
            <td>${APP.canEdit ? `<button class="btn sm danger" data-unlink="${esc(c.customer_id)}" data-ml="${esc(c.manager_link_id)}" data-nm="${esc(c.name || c.customer_id)}">Unlink</button>` : ''}</td></tr>`).join('')}
          </tbody></table>`;
      };

      const root = $('#lkx');
      $('#lk_mcc').onchange = loadList;
      $('#lk_reload').onclick = loadList;
      loadList();

      $('#lk_go').onclick = e => A.busy(e.currentTarget, async () => {
        const [conn, mcc] = mccVal();
        const ids = $('#lk_ids').value.split('\n').map(x => x.trim()).filter(Boolean);
        const res = $('#lk_res');
        if (!ids.length) { res.innerHTML = '<div class="alert err" style="margin:0">Paste at least one Customer ID.</div>'; return; }
        try {
          const r = await A.api('mcc_link', {}, { conn, mcc, client_ids: ids, auto_accept: $('#lk_auto').checked });
          res.innerHTML = `<div class="alert ${r.failed ? 'warn' : 'ok'}" style="margin:0 0 8px"><b>${r.linked} linked</b>${r.failed ? ` · ${r.failed} failed` : ''}.</div>
            ${r.results.map(x => x.ok
              ? `<div class="list-item"><div class="grow"><div class="t" style="color:${x.status === 'ACTIVE' ? 'var(--ok)' : 'var(--warn,#b8860b)'}">${x.status === 'ACTIVE' ? '✓' : '⏳'} ${esc(A.fmtId(x.customer_id))} — ${x.status === 'ACTIVE' ? 'linked' : 'pending accept'}</div><div class="muted small">${esc(x.note || '')}</div></div></div>`
              : `<div class="list-item"><div class="grow"><div class="t" style="color:var(--bad)">✕ ${esc(A.fmtId(x.customer_id))}</div><div class="muted small">${esc(x.error || 'Failed')}</div></div></div>`).join('')}`;
          if (r.linked) { $('#lk_ids').value = ''; A.toast(`${r.linked} account(s) linked`); loadList(); }
        } catch (err) { res.innerHTML = `<div class="alert err" style="margin:0">${esc(err.error || 'Error')}</div>`; }
      });

      root.addEventListener('click', async e => {
        const b = e.target.closest('[data-unlink]'); if (!b) return;
        const [conn, mcc] = mccVal();
        if (!await A.confirmBox(`Unlink <b>${esc(b.dataset.nm)}</b> from this manager account?<br><span class="muted small">The account keeps all its campaigns; it just leaves the MCC.</span>`, 'Unlink', true)) return;
        A.busy(b, async () => {
          try { await A.api('mcc_unlink', {}, { conn, mcc, client_id: b.dataset.unlink, manager_link_id: b.dataset.ml }); A.toast('Account unlinked'); loadList(); }
          catch (err) { A.showError(err); }
        });
      });
    },
  };
})();
