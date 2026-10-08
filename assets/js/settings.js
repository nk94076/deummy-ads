/* Settings: my Google accounts, users (admin), password */
(() => {
  const { $, esc, icon } = A;

  function userModal(u, done) {
    const isNew = !u;
    const m = A.modal({
      title: isNew ? 'New user' : 'User: ' + esc(u.username),
      body: `${isNew ? `<label class="f">Username <span class="muted">(for login, e.g. rahul or rahul@client.com)</span></label><input id="un" autocomplete="off" placeholder="rahul">
        <div class="hint" id="unHint">Only a-z, 0-9 and . _ @ + - . Spaces become "." automatically.</div>` : ''}
        <label class="f">Name</label><input id="nm" value="${esc(u?.name || '')}">
        <label class="f">Role</label><select id="rl"><option value="user">User - own connected accounts only</option><option value="admin" ${u?.role === 'admin' ? 'selected' : ''}>Admin - can also manage users</option></select>
        <label class="f">${isNew ? 'Password' : 'New password <span class="muted">(empty = keep current)</span>'}</label><input id="pw" type="text" autocomplete="new-password" placeholder="min 8 characters">
        <button type="button" class="btn sm" id="gen" style="margin-top:8px">${icon('key', 'sm')} Generate strong password</button>
        <div class="alert info" style="margin-top:16px">After logging in, the user can <b>link a Google account</b> (sidebar) to connect any Google Ads account or manager account. Each user sees only their own connected accounts.</div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="sv">${isNew ? 'Create user' : 'Save'}</button>`,
    });
    m.$('#gen').onclick = () => { const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#'; m.$('#pw').value = Array.from(crypto.getRandomValues(new Uint32Array(14)), x => c[x % c.length]).join(''); };
    m.$('#sv').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        if (isNew) await A.api('user_create', {}, { username: m.$('#un').value, name: m.$('#nm').value, role: m.$('#rl').value, password: m.$('#pw').value });
        else await A.api('user_update', {}, { username: u.username, name: m.$('#nm').value, role: m.$('#rl').value, password: m.$('#pw').value });
        const pw = m.$('#pw').value;
        m.close(); done();
        if (pw) A.modal({ title: 'Login details', body: `<p style="margin-top:14px">Send these details to the user (the password won't be shown again):</p><pre class="example">URL: ${esc(location.origin + location.pathname)}\nUsername: ${esc(isNew ? m.$('#un').value.trim().toLowerCase().replace(/\s+/g, '.') : u.username)}\nPassword: ${esc(pw)}</pre>`, foot: '<button class="btn primary" data-close>Done</button>' });
        else A.toast('User saved');
      } catch (err) {
        let box = m.$('#uErr');
        if (!box) { m.$('.modal-body').insertAdjacentHTML('beforeend', '<div class="alert err" id="uErr" style="margin-top:14px"></div>'); box = m.$('#uErr'); }
        box.textContent = err.error || 'Error';
      }
    });
    const un = m.$('#un');
    if (un) un.oninput = () => {
      const v = un.value.trim().toLowerCase().replace(/\s+/g, '.');
      const bad = [...new Set(v.replace(/[a-z0-9._@+-]/g, ''))].join(' ');
      m.$('#unHint').innerHTML = bad ? `<span class="bad">These characters are not allowed: ${esc(bad)}</span>` : (v ? `Username will be: <b>${esc(v)}</b>` : 'Only a-z, 0-9 and . _ @ + - . Spaces become "." automatically.');
    };
  }

  // ---- Two-factor authentication (TOTP) ----
  async function enable2faModal(done) {
    let d;
    try { d = await A.api('twofa_setup', {}, {}); } // body forces POST (mutates session)
    catch (err) { return A.showError(err); }
    const m = A.modal({
      title: 'Turn on two-factor authentication',
      body: `<p class="muted small" style="margin:0 0 14px">Use an authenticator app (Google Authenticator, Authy, Microsoft Authenticator, 1Password...). Add a new account in the app using the setup key below, then enter the 6-digit code it shows.</p>
        <label class="f">Setup key <span class="muted">(type this into your app)</span></label>
        <pre class="example" id="tkey" style="user-select:all;cursor:pointer" title="Click to copy">${esc(d.secret)}</pre>
        <button type="button" class="btn sm" id="tcopy" style="margin:4px 0 14px">${icon('copy', 'sm')} Copy key</button>
        <details style="margin-bottom:14px"><summary class="muted small" style="cursor:pointer">Or use the setup link (otpauth://)</summary>
          <pre class="example" style="user-select:all;white-space:pre-wrap;word-break:break-all;margin-top:8px">${esc(d.uri)}</pre></details>
        <label class="f">6-digit code from the app</label>
        <input id="tcode" inputmode="numeric" maxlength="6" placeholder="123456" style="letter-spacing:.3em;text-align:center;font-size:18px">
        <div class="alert err" id="t2err" style="margin-top:12px;display:none"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="t2ok">Verify &amp; turn on</button>`,
    });
    const copy = t => navigator.clipboard?.writeText(t).then(() => A.toast('Copied'));
    m.$('#tcopy').onclick = () => copy(d.secret_raw);
    m.$('#tkey').onclick = () => copy(d.secret_raw);
    m.$('#t2ok').onclick = e => A.busy(e.currentTarget, async () => {
      try { await A.api('twofa_enable', {}, { code: m.$('#tcode').value }); m.close(); A.toast('Two-factor authentication is on'); done(); }
      catch (err) { const b = m.$('#t2err'); b.style.display = 'block'; b.textContent = err.error || 'Error'; }
    });
  }
  function disable2faModal(done) {
    const m = A.modal({
      title: 'Turn off two-factor authentication',
      body: `<p class="muted small" style="margin:0 0 14px">Enter your current password to confirm.</p>
        <label class="f">Password</label><input id="d2pw" type="password" autocomplete="current-password">
        <div class="alert err" id="d2err" style="margin-top:12px;display:none"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn danger" id="d2ok">Turn off</button>`,
    });
    m.$('#d2ok').onclick = e => A.busy(e.currentTarget, async () => {
      try { await A.api('twofa_disable', {}, { password: m.$('#d2pw').value }); m.close(); A.toast('Two-factor authentication is off'); done(); }
      catch (err) { const b = m.$('#d2err'); b.style.display = 'block'; b.textContent = err.error || 'Error'; }
    });
  }

  // ---- White-label branding editor (super admin) ----
  async function brandModal(scope, username, title) {
    let cur = {};
    try { const d = await A.api('brand_get', { scope, username: username || '' }); cur = d.brand || d.builtin || {}; }
    catch (e) { return A.showError(e); }
    const v = k => esc(cur[k] || '');
    const m = A.modal({
      title, wide: true,
      body: `<div class="grid2" style="gap:0 18px">
          <div>
            <label class="f">Brand name</label><input id="br_name" value="${v('name')}" placeholder="TrakrHub">
            <label class="f">Logo text <span class="muted">(1–3 letters or an emoji)</span></label><input id="br_logo" value="${v('logo')}" maxlength="3" placeholder="A">
            <label class="f">Logo image URL <span class="muted">(optional, https)</span></label><input id="br_url" value="${v('logo_url')}" placeholder="https://…/logo.png">
            <label class="f">Primary colour</label><input id="br_color" type="color" value="${cur.color || '#2563eb'}" style="width:64px;height:40px;padding:2px;cursor:pointer">
          </div>
          <div>
            <label class="f">Preview</label>
            <div id="br_p1" style="border:1px solid var(--line);border-radius:12px;padding:18px;background:var(--nav-1)"></div>
            <div id="br_p2" style="border:1px solid var(--line);border-radius:12px;padding:18px;margin-top:10px;display:flex;gap:8px;align-items:center"></div>
          </div>
        </div>
        <div class="alert err" id="br_err" style="display:none;margin-top:12px"></div>`,
      foot: `<button class="btn" data-close>Cancel</button><button class="btn ghost" id="br_clear">Reset to default</button><button class="btn primary" id="br_save">Save branding</button>`,
    });
    const get = () => ({ name: m.$('#br_name').value, logo: m.$('#br_logo').value, logo_url: m.$('#br_url').value, color: m.$('#br_color').value });
    const preview = () => {
      const b = get(); const nm = b.name || 'TrakrHub'; const parts = nm.split(' ');
      const logo = b.logo_url
        ? `<span class="logo"><img src="${esc(b.logo_url)}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit"></span>`
        : `<span class="logo" style="background:${b.color}">${esc(b.logo || nm[0] || 'A')}</span>`;
      m.$('#br_p1').innerHTML = `<div class="brand" style="color:#fff">${logo} ${esc(parts[0])}${parts.length > 1 ? ` <span class="b2" style="color:${b.color}">${esc(parts.slice(1).join(' '))}</span>` : ''}</div>`;
      m.$('#br_p2').innerHTML = `<button class="btn" style="background:${b.color};color:#fff;border-color:${b.color}">Primary button</button><a href="#" style="color:${b.color}" onclick="return false">A link</a>`;
    };
    ['#br_name', '#br_logo', '#br_url', '#br_color'].forEach(s => { const el = m.$(s); el.oninput = preview; el.onchange = preview; });
    preview();
    m.$('#br_clear').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        await A.api('brand_set', {}, { scope, username: username || '', clear: true });
        m.close(); A.toast('Reset to default');
        if (APP.user.super && scope === 'super') location.reload();
      } catch (err) { const b = m.$('#br_err'); b.style.display = 'block'; b.textContent = err.error || 'Error'; }
    });
    m.$('#br_save').onclick = e => A.busy(e.currentTarget, async () => {
      try {
        const r = await A.api('brand_set', {}, { scope, username: username || '', brand: get() });
        m.close(); A.toast('Branding saved');
        if (APP.user.super && scope === 'super') A.applyBrand(r.brand);
      } catch (err) { const b = m.$('#br_err'); b.style.display = 'block'; b.textContent = err.error || 'Error'; }
    });
  }

  A.views.settings = {
    eyebrow: 'Settings', dates: false, needsAccount: false,
    async render(el) {
      const u = APP.user;
      el.innerHTML = `<section class="card"><div class="card-head"><h2>${icon('link')} Google accounts</h2><a class="btn primary" href="connect.php">${icon('plus', 'sm')} Connect / manage</a></div><div id="conns"><p style="padding:20px;text-align:center"><span class="spin"></span></p></div></section>
        ${!u.super ? `<section class="card"><div class="card-head"><h2>${icon('shield')} Two-factor authentication</h2></div><div class="form-card" style="max-width:520px" id="twofaBox"><p style="padding:6px 0"><span class="spin"></span></p></div></section>
        <section class="card"><div class="card-head"><h2>${icon('key')} Change password</h2></div><div class="form-card" style="max-width:460px">
          <label class="f">Current password</label><input id="cp" type="password"><label class="f">New password</label><input id="np" type="password" placeholder="min 8 characters">
          <div class="save-bar" style="justify-content:flex-start"><button class="btn primary" id="cpw">Update password</button></div></div></section>` : ''}`;

      if (!u.super) {
        const loadTwofa = async () => {
          const box = $('#twofaBox'); if (!box) return;
          let s; try { s = await A.api('twofa_status'); } catch { box.innerHTML = '<p class="muted small">Could not load 2FA status.</p>'; return; }
          box.innerHTML = s.on
            ? `<div class="row" style="justify-content:space-between;align-items:center;gap:12px"><div><b style="color:var(--ok)">On</b><div class="muted small">Your account asks for an authenticator code at sign-in.</div></div><button class="btn danger sm" id="t2off">Turn off</button></div>`
            : `<div class="row" style="justify-content:space-between;align-items:center;gap:12px"><div><b>Off</b><div class="muted small">Add a second step at sign-in using an authenticator app.</div></div><button class="btn primary sm" id="t2on">Turn on</button></div>`;
          const on = $('#t2on'), off = $('#t2off');
          if (on) on.onclick = () => enable2faModal(loadTwofa);
          if (off) off.onclick = () => disable2faModal(loadTwofa);
        };
        loadTwofa();
      }

      if (u.super) {
        const bm = $('#brMine'); if (bm) bm.onclick = () => brandModal('super', '', 'My branding (super admin)');
        const bd = $('#brDefault'); if (bd) bd.onclick = () => brandModal('default', '', 'Default branding for all users');
      }

      A.api('connections').then(d => {
        if (!$('#conns')) return;
        $('#conns').innerHTML = d.connections.length ? d.connections.map(c => `<div class="list-item"><div class="avatar" style="background:var(--accent-2);color:var(--accent)">${esc(c.email[0].toUpperCase())}</div>
          <div class="grow"><div class="t">${esc(c.email)}</div><div class="muted small">Connected ${esc(c.created)}${c.accounts !== null ? ` · ${c.accounts} account(s)` : ''}
          ${c.roots.filter(r => r.type === 'MCC').map(r => ` · MCC: ${esc(r.name)} (${r.count})`).join('')}</div></div></div>`).join('')
          : `<div class="card-body"><div class="alert info" style="margin:0">No Google account connected yet${A.state.demoLabels ? ', so the dashboard shows demo data' : ''}. <a href="connect.php"><b>Connect now →</b></a></div></div>`;
      }).catch(A.showError);

      if (u.role === 'admin' && $('#users')) {
        const loadUsers = async () => {
          const d = await A.api('users');
          $('#users').innerHTML = `<table class="data"><thead><tr><th class="l nosort">User</th><th class="l nosort">Role</th><th class="nosort">Google accounts</th><th class="l nosort">Created</th><th class="nosort"></th></tr></thead><tbody>
            ${d.super ? `<tr><td class="l"><b>${esc(d.super)}</b><span class="sub">Super admin (config.php)</span></td><td class="l">Admin</td><td>—</td><td class="l">—</td><td></td></tr>` : ''}
            ${d.users.map(x => `<tr><td class="l"><b>${esc(x.name)}</b><span class="sub">${esc(x.username)}</span></td><td class="l">${esc(x.role === 'admin' ? 'Admin' : 'User')}${x.disabled ? ' <span class="badge">disabled</span>' : ''}${x.twofa ? ' <span class="badge" title="Two-factor is on">2FA</span>' : ''}</td>
              <td>${x.connections}</td><td class="l">${esc(x.created)}</td>
              <td>${APP.user.super ? `<button class="btn sm" data-ulogin="${esc(x.username)}" title="Open this user's account to inspect it (Exit returns you to super admin)">${icon('link', 'sm')} Login as</button>` : ''}<button class="btn sm" data-ue="${esc(x.username)}">${icon('edit', 'sm')} Edit</button> <button class="btn sm" data-ud="${esc(x.username)}">${x.disabled ? 'Enable' : 'Disable'}</button>${x.twofa ? ` <button class="btn sm" data-u2="${esc(x.username)}" title="Clear this user's 2FA (lost phone)">Reset 2FA</button>` : ''}${APP.user.super ? ` <button class="btn sm" data-ub="${esc(x.username)}" title="White-label branding for this user">${icon('shield', 'sm')} Brand</button>` : ''} <button class="btn sm danger" data-ux="${esc(x.username)}">${icon('trash', 'sm')}</button></td></tr>`).join('')}
            </tbody></table>${d.users.length ? '' : '<p class="muted" style="padding:0 24px 20px">Only you so far. Use "New user" to create logins for team members or clients.</p>'}`;
          return d.users;
        };
        let users = [];
        try { users = await loadUsers(); } catch (e) { A.showError(e); }
        $('#newU').onclick = () => userModal(null, async () => users = await loadUsers());
        $('#users').onclick = async e => {
          const b = e.target.closest('[data-ue],[data-ud],[data-ux],[data-u2],[data-ub],[data-ulogin]'); if (!b) return;
          const x = users.find(v => v.username === (b.dataset.ue || b.dataset.ud || b.dataset.ux || b.dataset.u2 || b.dataset.ub || b.dataset.ulogin));
          if (b.dataset.ulogin) {
            try { await A.api('impersonate_start', {}, { username: x.username }); A.toast('Opening ' + x.username + '…'); location.href = './'; }
            catch (err) { A.showError(err); }
            return;
          }
          if (b.dataset.ub) return brandModal('user', x.username, 'Branding: ' + x.username);
          if (b.dataset.ue) return userModal(x, async () => users = await loadUsers());
          if (b.dataset.ud) { await A.api('user_update', {}, { username: x.username, disabled: !x.disabled }); users = await loadUsers(); return A.toast('Updated'); }
          if (b.dataset.u2) {
            if (await A.confirmBox(`Reset two-factor for <b>${esc(x.username)}</b>? They'll sign in with just their password until they set it up again.`, 'Reset 2FA', true)) {
              await A.api('twofa_reset', {}, { username: x.username }); users = await loadUsers(); A.toast('2FA reset');
            }
            return;
          }
          if (b.dataset.ux && await A.confirmBox(`Delete user <b>${esc(x.username)}</b>? Their Google connections will be removed too.`, 'Delete', true)) {
            await A.api('user_delete', {}, { username: x.username }); users = await loadUsers(); A.toast('User deleted');
          }
        };
      }
      const cpw = $('#cpw');
      if (cpw) cpw.onclick = e => A.busy(e.currentTarget, async () => {
        try { await A.api('change_password', {}, { current: $('#cp').value, new: $('#np').value }); A.toast('Password changed'); $('#cp').value = $('#np').value = ''; }
        catch (err) { A.toast(err.error || 'Error'); }
      });
    },
  };
})();
