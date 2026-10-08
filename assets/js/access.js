/* Access & sharing: Google Ads account users/invitations + share accounts with tool users */
(() => {
  const { $, $$, esc, icon, state, label } = A;
  const ROLES = [
    ['ADMIN', 'Admin', 'Everything: campaigns, billing, manage users'],
    ['STANDARD', 'Standard', 'Edit campaigns, but not users/billing'],
    ['READ_ONLY', 'Read only', 'Can view only, cannot make changes'],
    ['EMAIL_ONLY', 'Email only', 'Reports/notifications by email only'],
  ];
  const roleName = r => (ROLES.find(x => x[0] === r) || [r, label(r)])[1];
  const loading = '<p style="padding:24px;text-align:center"><span class="spin"></span></p>';

  A.views.access = {
    eyebrow: 'Access & sharing', dates: false, needsAccount: false,
    async render(el) {
      const acc = state.acc;
      el.innerHTML = `<div id="acx">
        <div class="acx-intro">
          <div class="acx-card"><div class="acx-ic">${icon('shield')}</div><div><b>Google Ads account access</b><p>Give an email Admin / Standard / Read-only access inside Google Ads. Same as Google Ads "Access and security".</p></div></div>
          <div class="acx-card"><div class="acx-ic alt">${icon('users')}</div><div><b>Share with tool users</b><p>Give a user of this tool access to your connected account (View only or Can edit). They don't need a Google login.</p></div></div>
        </div>

        <section class="card" id="gaCard"><div class="card-head"><h2>${icon('shield')} Google Ads access ${acc ? `<span class="pill">${esc(acc.name)}</span>` : ''}</h2>
          ${acc && APP.canEdit && (acc.access || 'owner') === 'owner' ? `<button class="btn primary" id="invBtn">${icon('mail', 'sm')} Invite user</button>` : ''}</div>
          <div id="gaBody">${loading}</div></section>

        <section class="card"><div class="card-head"><h2>${icon('users')} Share with tool users</h2>
          <button class="btn primary" id="shBtn">${icon('plus', 'sm')} Share account</button></div>
          <div id="shBody">${loading}</div></section>
      </div>`;
      const root = $('#acx');
      loadGoogle();
      loadShares();

      async function loadGoogle() {
        const box = $('#gaBody');
        if (!acc) { box.innerHTML = '<div class="card-body muted">Select an account above.</div>'; return; }
        if ((acc.access || 'owner') !== 'owner') {
          box.innerHTML = `<div class="card-body"><div class="alert info" style="margin:0">This account is <b>shared</b> with you (by ${esc(acc.shared_by || '')}). Only the user who connected the Google account can manage Google Ads users.</div></div>`;
          return;
        }
        let d;
        try { d = await A.get('access_list'); } catch (e) { box.innerHTML = `<div class="card-body"><div class="alert err" style="margin:0">${esc(e.error || 'Error')}</div></div>`; return; }
        if (d.blocked) { box.innerHTML = A.blockedHtml(d.blocked); return; }
        box.innerHTML = `<div class="table-wrap"><table class="data"><thead><tr><th class="l">User</th><th class="l">Access level</th><th class="l">Since</th><th class="l">Added by</th><th></th></tr></thead><tbody>
          ${d.users.map(u => `<tr><td class="l"><div class="u-cell"><span class="avatar sm">${esc((u.email[0] || '?').toUpperCase())}</span><b>${esc(u.email)}</b></div></td>
            <td class="l">${APP.canEdit ? `<select data-role="${esc(u.id)}" data-email="${esc(u.email)}" class="role-sel">${ROLES.map(([k, n]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${n}</option>`).join('')}</select>` : `<span class="chip">${esc(roleName(u.role))}</span>`}</td>
            <td class="l">${esc(u.since || '—')}</td><td class="l muted small">${esc(u.by || '—')}</td>
            <td>${APP.canEdit ? `<button class="btn sm danger" data-rmu="${esc(u.id)}" data-email="${esc(u.email)}" title="Remove access">${icon('trash', 'sm')}</button>` : ''}</td></tr>`).join('') || '<tr><td class="empty" colspan="5">No users</td></tr>'}
          </tbody></table></div>
          <div class="sub-h">Pending invitations <span class="pill">${d.invites.length}</span></div>
          <div class="table-wrap"><table class="data"><thead><tr><th class="l">Email</th><th class="l">Access level</th><th class="l">Sent</th><th></th></tr></thead><tbody>
          ${d.invites.map(i => `<tr><td class="l"><b>${esc(i.email)}</b></td><td class="l"><span class="chip">${esc(roleName(i.role))}</span></td><td class="l">${esc(i.sent)}</td>
            <td>${APP.canEdit ? `<button class="btn sm" data-rev="${esc(i.id)}" data-email="${esc(i.email)}">Cancel invite</button>` : ''}</td></tr>`).join('') || '<tr><td class="empty" colspan="4">No pending invitations</td></tr>'}
          </tbody></table></div>
          <div class="card-body"><div class="hint"><b>About the invite email:</b> Google (not this tool) sends it, and it can take a few minutes — ask the person to check spam. The invitation shows under "Pending" above the moment it is created, so if it appears there, it was sent. Only the invited person can accept it, by clicking the link in their email — it can't be accepted from here (that is a Google security rule for user access). To link a whole account to your manager account instead — which you <i>can</i> auto-accept — use <a href="#link-accounts">Link existing accounts</a>. Some accounts may need extra security approval (see "Access and security" in Google Ads). Do not remove your own Admin access.</div></div>`;
      }

      async function loadShares() {
        const box = $('#shBody');
        let d;
        try { d = await A.api('shares'); } catch (e) { box.innerHTML = `<div class="card-body"><div class="alert err" style="margin:0">${esc(e.error || 'Error')}</div></div>`; return; }
        const rows = (list, mine) => list.map(s => `<tr><td class="l"><b>${esc(s.account_name)}</b><span class="sub">${s.customer_id === '*' ? 'All accounts' : esc(A.fmtId(s.customer_id))} · ${esc(s.email || '')}</span></td>
          <td class="l">${esc(mine ? s.shared_with : s.owner)}</td><td class="l"><span class="chip ${s.role === 'edit' ? 'on' : ''}">${s.role === 'edit' ? 'Can edit' : 'View only'}</span></td>
          <td class="l">${esc(String(s.created_at).slice(0, 10))}</td><td><button class="btn sm danger" data-unshare="${s.id}" data-name="${esc(s.account_name)}">${icon('trash', 'sm')} ${mine ? 'Remove' : 'Leave'}</button></td></tr>`).join('');
        box.innerHTML = `${state.demo ? '<div class="card-body"><div class="alert info" style="margin:0">Demo mode: connect your Google account first to share.</div></div>' : ''}
          <div class="sub-h">Shared by me <span class="pill">${d.given.length}</span></div>
          <div class="table-wrap"><table class="data"><thead><tr><th class="l">Account</th><th class="l">Kisko</th><th class="l">Access</th><th class="l">Date</th><th></th></tr></thead>
            <tbody>${rows(d.given, true) || '<tr><td class="empty" colspan="5">Nothing shared yet</td></tr>'}</tbody></table></div>
          <div class="sub-h">Shared with me <span class="pill">${d.received.length}</span></div>
          <div class="table-wrap"><table class="data"><thead><tr><th class="l">Account</th><th class="l">Kisne</th><th class="l">Access</th><th class="l">Date</th><th></th></tr></thead>
            <tbody>${rows(d.received, false) || '<tr><td class="empty" colspan="5">Nothing shared with you</td></tr>'}</tbody></table></div>
          <div class="card-body"><div class="hint"><b>View only</b>: can view reports and campaigns but not change anything. <b>Can edit</b>: can change campaigns, budget, URLs and the rotator. The Google email/password is never shared.</div></div>`;
      }

      root.addEventListener('click', async e => {
        if (e.target.closest('#invBtn')) return inviteModal();
        if (e.target.closest('#shBtn')) return shareModal();
        const rmu = e.target.closest('[data-rmu]');
        if (rmu) {
          if (!await A.confirmBox(`Remove Google Ads access for <b>${esc(rmu.dataset.email)}</b>?`, 'Remove', true)) return;
          try { await A.post('access_remove', { user_id: rmu.dataset.rmu, email: rmu.dataset.email }); A.toast('Access removed'); loadGoogle(); } catch (err) { A.showError(err); }
        }
        const rev = e.target.closest('[data-rev]');
        if (rev) {
          try { await A.post('access_revoke', { invite_id: rev.dataset.rev, email: rev.dataset.email }); A.toast('Invite cancel ✓'); loadGoogle(); } catch (err) { A.showError(err); }
        }
        const un = e.target.closest('[data-unshare]');
        if (un) {
          if (!await A.confirmBox(`Remove the share for <b>${esc(un.dataset.name)}</b>?`, 'Remove', true)) return;
          try { await A.api('share_remove', {}, { id: +un.dataset.unshare }); A.toast('Share removed'); loadShares(); A.loadAccounts().catch(() => {}); } catch (err) { A.showError(err); }
        }
      });
      root.addEventListener('change', async e => {
        const s = e.target.closest('[data-role]');
        if (!s) return;
        if (!await A.confirmBox(`Change access for <b>${esc(s.dataset.email)}</b> to <b>${esc(roleName(s.value))}</b>?`)) { loadGoogle(); return; }
        try { await A.post('access_role', { user_id: s.dataset.role, role: s.value, email: s.dataset.email }); A.toast('Access level changed'); } catch (err) { A.showError(err); loadGoogle(); }
      });

      function inviteModal() {
        const m = A.modal({
          title: 'Invite a user to Google Ads',
          body: `<p class="muted small" style="margin-top:12px">Account: <b>${esc(acc.name)}</b> · ${esc(A.fmtId(acc.id))}</p>
            <label class="f">Email address</label><input id="iv_em" type="email" placeholder="name@gmail.com" autocomplete="off">
            <label class="f">Access level</label>
            <div class="role-list">${ROLES.map(([k, n, d], i) => `<label class="role-o"><input type="radio" name="ivr" value="${k}" ${i === 2 ? 'checked' : ''}><b>${n}</b><span>${d}</span></label>`).join('')}</div>
            <div class="alert info" style="margin-top:14px">Google will send an invitation to this email. After they accept, the user can see this account in Google Ads.</div>`,
          foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="iv_go">${icon('mail', 'sm')} Send invite</button>`,
        });
        m.$('#iv_em').focus();
        m.$('#iv_go').onclick = ev => A.busy(ev.currentTarget, async () => {
          try {
            const role = m.$('input[name=ivr]:checked').value;
            if (role === 'ADMIN' && !await A.confirmBox('Admin access lets this user manage billing and other users too. Continue?', 'Give Admin')) return;
            await A.post('access_invite', { email: m.$('#iv_em').value.trim(), role });
            m.close(); A.toast('Invitation sent'); loadGoogle();
          } catch (err) { errIn(m, err); }
        });
      }

      async function shareModal() {
        const mine = state.accounts.filter(a => (a.access || 'owner') === 'owner' && !String(a.conn).startsWith('demo'));
        let users = [];
        try { users = (await A.api('share_users')).users; } catch {}
        const conns = [...new Map(mine.map(a => [a.conn, a.email])).entries()];
        const m = A.modal({
          title: 'Share account',
          body: mine.length ? `<label class="f">Account</label><select id="sh_acc">
              ${conns.map(([cn, em]) => `<optgroup label="${esc(em)}"><option value="${esc(cn)}|*">★ ALL accounts of this login</option>
                ${mine.filter(a => a.conn === cn).map(a => `<option value="${esc(cn)}|${esc(a.id)}" ${state.acc && a.id === state.acc.id && a.conn === state.acc.conn ? 'selected' : ''}>${esc(a.name)} (${esc(A.fmtId(a.id))})</option>`).join('')}</optgroup>`).join('')}</select>
            <label class="f">Tool user</label>${users.length ? `<select id="sh_user">${users.map(u => `<option value="${esc(u.username)}">${esc(u.name)} (${esc(u.username)})</option>`).join('')}</select>` : '<input id="sh_user" placeholder="username">'}
            <div class="hint">User not listed? Create them in Settings → Users first.</div>
            <label class="f">Access</label>
            <div class="role-list"><label class="role-o"><input type="radio" name="shr" value="view" checked><b>View only</b><span>Can view reports, campaigns and keywords</span></label>
              <label class="role-o"><input type="radio" name="shr" value="edit"><b>Can edit</b><span>Campaigns banana/edit, budget, URLs, rotator</span></label></div>`
            : `<div class="alert info" style="margin-top:14px">Connect your Google account first to share (only your own connected accounts can be shared).</div>`,
          foot: `<button class="btn" data-close>Cancel</button>${mine.length ? `<button class="btn primary" id="sh_go">${icon('users', 'sm')} Share</button>` : ''}`,
        });
        if (!mine.length) return;
        m.$('#sh_go').onclick = ev => A.busy(ev.currentTarget, async () => {
          const [conn, cid] = m.$('#sh_acc').value.split('|');
          try {
            await A.api('share_add', {}, { conn, cid, username: m.$('#sh_user').value.trim(), role: m.$('input[name=shr]:checked').value });
            m.close(); A.toast('Shared'); loadShares();
          } catch (err) { errIn(m, err); }
        });
      }
      function errIn(m, err) {
        let b = m.$('.m-err');
        if (!b) { m.$('.modal-body').insertAdjacentHTML('beforeend', '<div class="alert err m-err" style="margin-top:14px"></div>'); b = m.$('.m-err'); }
        b.textContent = err.error || 'Error';
      }
    },
  };
})();
