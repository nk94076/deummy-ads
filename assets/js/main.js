/* App start */
(async () => {
  const { $ } = A;
  if (window.APP && APP.onboard) { A.icons(); return; } // onboarding overlay handles first-login setup
  A.icons();
  let saved = '7'; try { saved = localStorage.getItem('adhook_range') || '7'; } catch {}
  A.setRange(saved === 'custom' ? '7' : saved);

  $('#dateBtn').onclick = e => { e.stopPropagation(); A.datePicker($('#dateBtn')); };
  $('#accSwitch').onclick = e => { e.stopPropagation(); A.accountPicker($('#accSwitch')); };
  $('#copyId').onclick = () => { if (A.state.acc) { navigator.clipboard?.writeText(A.state.acc.id); A.toast('Customer ID copied: ' + A.fmtId(A.state.acc.id)); } };
  $('#refreshBtn').onclick = () => { A.state.report = null; A.route(); };
  $('#csvBtn').onclick = () => { const v = A.views[A.state.view]; v && v.csv && v.csv(); };
  $('#menuBtn').onclick = () => $('#sidebar').classList.toggle('open');
  window.addEventListener('hashchange', A.route);

  try { await A.loadAccounts(); } catch (e) { A.showError(e); }
  A.route();
})();
