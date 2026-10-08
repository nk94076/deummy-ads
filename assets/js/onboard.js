/* First-login onboarding gate: set profile + password, then 2FA. Blocks the app until done. */
(() => {
  if (!window.APP || !APP.onboard) return;
  const A = window.A;
  const esc = A ? A.esc : (s => String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])));
  const b = APP.brand || {};
  const badge = `<div class="brand">${b.logo_url
    ? `<span class="logo"><img src="${esc(b.logo_url)}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit"></span>`
    : `<span class="logo">${esc(b.logo || 'A')}</span>`} ${esc(b.name || 'AdHook Ads')}</div>`;

  const wrap = document.createElement('div');
  wrap.className = 'login-wrap';
  wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;overflow:auto;background:var(--bg);padding:24px 16px';
  document.body.appendChild(wrap);
  document.body.style.overflow = 'hidden';

  const state = { name: (APP.user && APP.user.name) || '', password: '' };
  const err = (m) => { const e = wrap.querySelector('#ob_err'); if (e) { e.style.display = 'block'; e.textContent = m; } };

  function step1() {
    wrap.innerHTML = `<form class="login-card" style="max-width:440px" onsubmit="return false">
      ${badge}
      <p class="muted">Welcome! Let's finish setting up your account.</p>
      <p class="muted small" style="margin:0 0 14px">Step 1 of 2 · Your profile</p>
      <label class="f">Your name<input id="ob_name" value="${esc(state.name)}" autofocus></label>
      <label class="f">Create a password<input id="ob_pw" type="password" placeholder="at least 8 characters" autocomplete="new-password"></label>
      <label class="f">Confirm password<input id="ob_pw2" type="password" autocomplete="new-password"></label>
      <div class="alert err" id="ob_err" style="display:none;margin-bottom:12px"></div>
      <button class="btn primary" id="ob_next">Continue</button>
    </form>`;
    wrap.querySelector('#ob_next').onclick = () => {
      const name = wrap.querySelector('#ob_name').value.trim();
      const pw = wrap.querySelector('#ob_pw').value;
      const pw2 = wrap.querySelector('#ob_pw2').value;
      if (!name) return err('Enter your name.');
      if (pw.length < 8) return err('Password must be at least 8 characters.');
      if (pw !== pw2) return err('The two passwords do not match.');
      state.name = name; state.password = pw;
      step2();
    };
  }

  async function step2() {
    wrap.innerHTML = `<form class="login-card" style="max-width:460px" onsubmit="return false">
      ${badge}
      <p class="muted small" style="margin:0 0 14px">Step 2 of 2 · Two-factor authentication</p>
      <p class="muted small" style="margin:0 0 12px">Add this account to an authenticator app (Google Authenticator, Authy, Microsoft Authenticator...), then enter the 6-digit code it shows.</p>
      <label class="f">Setup key <span class="muted">(type into your app)</span></label>
      <pre class="example" id="ob_key" style="user-select:all"><span class="spin"></span></pre>
      <details style="margin:8px 0 12px"><summary class="muted small" style="cursor:pointer">Or use the setup link (otpauth://)</summary>
        <pre class="example" id="ob_uri" style="white-space:pre-wrap;word-break:break-all;margin-top:8px"></pre></details>
      <label class="f">6-digit code<input id="ob_code" inputmode="numeric" maxlength="6" placeholder="123456" style="letter-spacing:.3em;text-align:center;font-size:18px"></label>
      <div class="alert err" id="ob_err" style="display:none;margin:12px 0"></div>
      <div class="row" style="gap:8px"><button class="btn" id="ob_back">Back</button><button class="btn primary" id="ob_fin" style="flex:1">Verify &amp; finish</button></div>
    </form>`;
    wrap.querySelector('#ob_back').onclick = step1;
    let secret = '';
    try {
      const d = await A.api('twofa_setup', {}, {});
      secret = d.secret_raw;
      wrap.querySelector('#ob_key').textContent = d.secret;
      wrap.querySelector('#ob_uri').textContent = d.uri;
    } catch (e) { err((e && e.error) || 'Could not start 2FA setup.'); }

    wrap.querySelector('#ob_fin').onclick = e => A.busy(e.currentTarget, async () => {
      const code = wrap.querySelector('#ob_code').value.trim();
      try {
        await A.api('twofa_enable', {}, { code });
        await A.api('onboard_complete', {}, { name: state.name, password: state.password });
        document.body.style.overflow = '';
        location.reload();
      } catch (e2) { err((e2 && e2.error) || 'That code is not right. Try the current 6-digit code.'); }
    });
  }

  step1();
})();
