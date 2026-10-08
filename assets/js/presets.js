/* Campaign Presets: a dedicated section to set up reusable blocked (excluded) locations
   + negative keywords under a panel name. Saved per-user; applied in the New Campaign wizard.
   Only the user who saved a preset sees it. */
(() => {
  const { $, $$, esc, icon } = A;

  const negPreview = s => { const lines = String(s || '').split('\n').map(x => x.trim()).filter(Boolean); return lines.length ? lines.slice(0, 6).join(', ') + (lines.length > 6 ? ` +${lines.length - 6}` : '') : ''; };

  A.views.presets = {
    eyebrow: 'Campaign Presets', needsAccount: true, dates: false,

    async render(el) {
      el.innerHTML = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';
      let L, data;
      try { [L, data] = await Promise.all([A.get('languages'), A.api('presets')]); }
      catch (e) { A.showError(e); el.innerHTML = ''; return; }
      const common = L.common_geos || [];
      let presets = data.presets || [];

      const rows = () => presets.length ? presets.map(p => `
        <div class="list-item" data-row="${p.id}">
          <div class="avatar" style="background:var(--accent-2);color:var(--accent)">${icon('pin', 'sm')}</div>
          <div class="grow">
            <div class="t">${esc(p.name)}</div>
            <div class="muted small">${p.excluded.length} blocked location${p.excluded.length === 1 ? '' : 's'}${p.excluded.length ? ': ' + p.excluded.slice(0, 4).map(g => esc(g.name)).join(', ') + (p.excluded.length > 4 ? '…' : '') : ''}${p.negatives ? ' · negatives: ' + esc(negPreview(p.negatives)) : ''}</div>
          </div>
          <button class="btn sm" data-edit="${p.id}">Edit</button>
          <button class="btn sm danger" data-del="${p.id}">Delete</button>
        </div>`).join('')
        : '<div class="muted" style="padding:18px">No presets yet. Create one — e.g. "Impact US" with the locations you always block + default negatives.</div>';

      el.innerHTML = `
        <section class="card">
          <div class="card-head"><h2>${icon('pin')} Campaign presets</h2>
            <div class="tools"><button class="btn sm primary" id="pr_new">${icon('plus', 'sm')} New preset</button></div></div>
          <div class="card-body">
            <p class="muted small" style="margin:0 0 12px">Default blocked (excluded) locations + negative keywords saved under a panel name. When you create a campaign, pick a preset in step 2 and these fill in automatically. Presets are private to your account.</p>
            <div id="pr_list">${rows()}</div>
          </div>
        </section>
        <section class="card hidden" id="pr_editor"></section>`;

      const listEl = $('#pr_list'), edEl = $('#pr_editor');
      let geo = null;

      function openEditor(preset) {
        edEl.classList.remove('hidden');
        edEl.innerHTML = `
          <div class="card-head"><h2>${icon(preset ? 'gear' : 'plus')} ${preset ? 'Edit preset' : 'New preset'}</h2></div>
          <div class="card-body">
            <label class="f">Panel name</label>
            <input id="pr_name" placeholder="e.g. Impact US, AWIN UK" value="${esc(preset?.name || '')}" style="max-width:340px">
            <label class="f" style="margin-top:14px">Blocked (excluded) locations</label>
            <div id="pr_geo"></div>
            <label class="f" style="margin-top:14px">Negative keywords <span class="muted">(one per line, optional)</span></label>
            <textarea id="pr_neg" rows="6" placeholder="free&#10;jobs&#10;customer care number">${esc(preset?.negatives || '')}</textarea>
            <div class="row" style="gap:10px;margin-top:16px">
              <button class="btn primary" id="pr_save">${icon('save', 'sm')} Save preset</button>
              <button class="btn" id="pr_cancel">Cancel</button>
            </div>
            <div class="alert err hidden" id="pr_err" style="margin-top:12px"></div>
          </div>`;
        geo = A.geoPicker($('#pr_geo'), preset?.excluded || [], common, { neg: true, empty: 'No locations blocked yet' });
        edEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        $('#pr_name').focus();

        $('#pr_cancel').onclick = () => { edEl.classList.add('hidden'); edEl.innerHTML = ''; geo = null; };
        $('#pr_save').onclick = e => A.busy(e.currentTarget, async () => {
          const name = $('#pr_name').value.trim();
          const err = $('#pr_err');
          if (!name) { err.classList.remove('hidden'); err.textContent = 'Panel name is required.'; return; }
          const excluded = geo.get(), negatives = $('#pr_neg').value;
          try {
            const r = await A.api('preset_save', {}, { name, excluded, negatives, id: preset?.id });
            if (preset) { Object.assign(preset, { name, excluded, negatives }); }
            else presets.push({ id: r.id, name, excluded, negatives });
            A.toast(`Preset "${name}" saved`);
            edEl.classList.add('hidden'); edEl.innerHTML = ''; geo = null;
            listEl.innerHTML = rows();
          } catch (e2) { err.classList.remove('hidden'); err.textContent = e2.error || 'Could not save'; }
        });
      }

      $('#pr_new').onclick = () => openEditor(null);
      listEl.addEventListener('click', async e => {
        const ed = e.target.closest('[data-edit]'); const del = e.target.closest('[data-del]');
        if (ed) { openEditor(presets.find(p => String(p.id) === ed.dataset.edit)); return; }
        if (del) {
          const p = presets.find(x => String(x.id) === del.dataset.del);
          if (!p || !confirm(`Delete preset "${p.name}"?`)) return;
          try { await A.api('preset_remove', {}, { id: p.id }); presets = presets.filter(x => x.id !== p.id); listEl.innerHTML = rows(); A.toast('Preset deleted'); }
          catch (err) { A.showError(err); }
        }
      });
    },
  };
})();
