/* Conversion tracking: list conversion actions, create/edit them, and copy the website tags */
(() => {
  const { $, $$, esc, icon, state, dec, money, label } = A;

  const CAT_LABEL = {
    PURCHASE: 'Purchase', ADD_TO_CART: 'Add to cart', BEGIN_CHECKOUT: 'Begin checkout', SUBSCRIBE_PAID: 'Subscribe',
    PHONE_CALL_LEAD: 'Phone call lead', IMPORTED_LEAD: 'Imported lead', SUBMIT_LEAD_FORM: 'Submit lead form',
    BOOK_APPOINTMENT: 'Book appointment', REQUEST_QUOTE: 'Request quote', GET_DIRECTIONS: 'Get directions',
    OUTBOUND_CLICK: 'Outbound click', CONTACT: 'Contact', ENGAGEMENT: 'Engagement', PAGE_VIEW: 'Page view',
    SIGN_UP: 'Sign-up', DOWNLOAD: 'Download', DEFAULT: 'Other',
  };
  const catName = c => CAT_LABEL[c] || label(c || '');
  const loading = '<div class="card" style="padding:40px;text-align:center"><span class="spin"></span></div>';

  let META = { categories: [], types: [] };

  A.views.conversions = {
    eyebrow: 'Conversions',
    async render(el) {
      el.innerHTML = loading;
      let d;
      try { d = await A.get('conversions'); }
      catch (e) { A.showError(e); el.innerHTML = ''; return; }
      if (d.blocked) { el.innerHTML = A.blockedHtml(d.blocked); return; }
      META = { categories: d.categories || [], types: d.types || [] };
      const rows = d.conversions || [];
      const active = rows.filter(r => r.status === 'ENABLED').length;

      el.innerHTML = `
        <section class="card">
          <div class="card-head">
            <h2>${icon('target')} Conversion tracking <span class="pill">${rows.length}</span></h2>
            ${(APP.canEdit && state.acc?.access !== 'view') ? `<button class="btn primary" id="newC">${icon('plus', 'sm')} New conversion action</button>` : ''}
          </div>
          <div class="card-body" style="padding-top:0">
            <div class="alert ${active ? 'ok' : 'warn'}" style="margin:0">
              ${active
                ? `${icon('check', 'sm')} ${active} active conversion action${active > 1 ? 's' : ''}. Smart Bidding (Maximize conversions / Target CPA / ROAS) can use these.`
                : `${icon('alert', 'sm')} No active conversion actions. Create one below, add its tag to your website, then Smart Bidding can optimize for conversions.`}
            </div>
          </div>
          <div class="table-wrap" id="cT"></div>
        </section>`;

      const t = new A.Table($('#cT'), [
        { k: 'name', label: 'Conversion action', cls: 'l', html: r => `<b>${esc(r.name)}</b><span class="sub">${esc(catName(r.category))}${r.primary ? ' · Primary' : ' · Secondary'}${r.type && r.type !== 'WEBPAGE' ? ' · ' + esc(label(r.type)) : ''}</span>` },
        { k: 'status', label: 'Status', cls: 'l', html: r => `<span class="status st-${r.status === 'ENABLED' ? 'ENABLED' : 'PAUSED'}">${r.status === 'ENABLED' ? 'Active' : label(r.status)}</span>` },
        { k: 'counting', label: 'Count', cls: 'l', html: r => r.counting === 'MANY_PER_CLICK' ? 'Every' : 'One' },
        { k: 'window_days', label: 'Window', html: r => (r.window_days || 30) + 'd' },
        { k: 'default_value', label: 'Value', html: r => r.default_value ? money(r.default_value) + (r.always_default ? '' : '*') : '—' },
        { k: 'conv', label: 'Conversions', html: r => dec(r.conv || 0) },
        { k: 'e', label: '', sort: false, html: r => `<button class="btn sm" data-tag="${r.id}" title="Show website tag">${icon('bulb', 'sm')}</button>${(APP.canEdit && state.acc?.access !== 'view') ? `<button class="btn sm" data-edit="${r.id}" title="Edit">${icon('edit', 'sm')}</button>` : ''}` },
      ], { rows, sortKey: 'conv', empty: 'No conversion actions yet' });
      t.render();

      const find = id => rows.find(r => r.id === id);
      $('#newC') && ($('#newC').onclick = () => convModal(null, () => this.render(el)));
      $('#cT').onclick = e => {
        const tag = e.target.closest('[data-tag]');
        if (tag) return tagModal(find(tag.dataset.tag));
        const ed = e.target.closest('[data-edit]');
        if (ed) return convModal(find(ed.dataset.edit), () => this.render(el));
      };
    },
  };

  function convForm(c) {
    const cur = (state.acc?.currency) || 'INR';
    const catOpts = META.categories.map(x => `<option value="${x}" ${c && c.category === x ? 'selected' : ''}>${esc(catName(x))}</option>`).join('');
    return `
      <label class="f">Name</label><input id="c_name" value="${esc(c?.name || '')}" placeholder="e.g. Purchase, Lead form, Sign-up">
      <div class="grid2">
        <div><label class="f">Category</label><select id="c_cat">${catOpts}</select></div>
        <div><label class="f">Count</label><select id="c_count">
          <option value="ONE_PER_CLICK" ${c?.counting === 'ONE_PER_CLICK' ? 'selected' : ''}>One — best for leads</option>
          <option value="MANY_PER_CLICK" ${c?.counting === 'MANY_PER_CLICK' ? 'selected' : ''}>Every — best for sales</option>
        </select></div>
      </div>
      <div class="grid2">
        <div><label class="f">Default value <span class="muted">(optional)</span></label><div class="in-pre"><span>${esc(cur)}</span><input id="c_val" type="number" step="0.01" min="0" value="${c?.default_value || ''}"></div></div>
        <div><label class="f">Click-through window</label><select id="c_win">
          ${[7, 14, 30, 60, 90].map(w => `<option value="${w}" ${(c?.window_days || 30) === w ? 'selected' : ''}>${w} days</option>`).join('')}
        </select></div>
      </div>
      <div class="grid2">
        <div><label class="f">Attribution</label><select id="c_attr">
          <option value="LAST_CLICK" ${(!c || c.attribution === 'LAST_CLICK') ? 'selected' : ''}>Last click</option>
          <option value="DATA_DRIVEN" ${c?.attribution === 'DATA_DRIVEN' ? 'selected' : ''}>Data-driven</option>
        </select><div class="hint">${c ? '' : 'New actions start on Last click; Google switches to Data-driven automatically once there is enough data.'}</div></div>
        <div><label class="f">Counts as</label><select id="c_primary">
          <option value="1" ${(!c || c.primary) ? 'selected' : ''}>Primary — used for bidding</option>
          <option value="0" ${c && !c.primary ? 'selected' : ''}>Secondary — observe only</option>
        </select></div>
      </div>
      <label class="row small" style="gap:8px;margin-top:10px"><input type="checkbox" id="c_always" ${c?.always_default ? 'checked' : ''}> Always use the default value (ignore any value sent by the tag)</label>
      <div class="hint">After saving, open the tag and add the site-wide tag once, plus the event snippet on the page that confirms the conversion.</div>`;
  }

  function readForm(m) {
    return {
      name: m.$('#c_name').value.trim(),
      category: m.$('#c_cat').value,
      counting: m.$('#c_count').value,
      default_value: m.$('#c_val').value || 0,
      currency: (state.acc?.currency) || 'INR',
      window_days: m.$('#c_win').value,
      attribution: m.$('#c_attr').value,
      primary: m.$('#c_primary').value === '1',
      always_default: m.$('#c_always').checked,
    };
  }

  function convModal(c, done) {
    const isNew = !c;
    const m = A.modal({
      title: isNew ? 'New conversion action' : 'Edit: ' + esc(c.name),
      wide: true,
      body: convForm(c),
      foot: `<button class="btn" data-close>Cancel</button><button class="btn primary" id="c_sv">${isNew ? 'Create' : 'Save'}</button>`,
    });
    m.$('#c_sv').onclick = e => A.busy(e.currentTarget, async () => {
      const data = readForm(m);
      if (!data.name) return A.toast('Enter a name');
      try {
        if (isNew) {
          const r = await A.post('conversion_create', { data });
          m.close();
          A.toast('Conversion action created');
          tagModal({ id: r.id, name: r.name, global_tag: r.global_tag, event_snippet: r.event_snippet, conversion_id: r.conversion_id }, true);
          done();
        } else {
          await A.post('conversion_update', { conversion_id: c.id, name: c.name, data });
          m.close();
          A.toast('Conversion action saved');
          done();
        }
      } catch (err) { A.showError(err); }
    });
  }

  function tagBlock(id, labelText, code, hint) {
    return `<div class="tag-b">
      <div class="tag-h"><b>${esc(labelText)}</b><button class="btn sm" data-copy="${id}">${icon('copy', 'sm')} Copy</button></div>
      <pre class="example" id="${id}">${esc(code)}</pre>
      ${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
  }

  async function tagModal(c, fresh = false) {
    const m = A.modal({
      title: 'Website tag: ' + esc(c.name || ''),
      wide: true,
      body: `<div class="tag-load"><span class="spin"></span> Loading tag…</div>`,
      foot: '<button class="btn primary" data-close>Done</button>',
    });
    let tag = c;
    if (!fresh || !c.global_tag) {
      try { tag = await A.get('conversion_tag', { conversion_id: c.id }); }
      catch (e) { m.$('.tag-load').outerHTML = `<div class="alert err">${esc(e.error || 'Could not load the tag')}</div>`; return; }
    }
    const body = m.el.querySelector('.modal-body') || m.$('.tag-load').parentElement;
    body.innerHTML = `
      ${fresh ? `<div class="alert ok" style="margin-top:0">${icon('check', 'sm')} Created. Add these tags to your website to start recording conversions.</div>` : ''}
      <div class="alert info" style="margin-top:${fresh ? '12px' : '0'}">Use gtag.js: add the <b>site-wide tag</b> to every page (once), and the <b>event snippet</b> only on the page that confirms the conversion (e.g. thank-you / order-confirmed).</div>
      ${tag.global_tag ? tagBlock('gtag', 'Site-wide tag (every page)', tag.global_tag, 'Paste inside &lt;head&gt; on all pages. If you already use Google Analytics / a global site tag, you can skip this.') : '<div class="alert warn">No gtag snippet available yet. It may take a minute after creation, or the account may use Google Tag Manager instead.</div>'}
      ${tag.event_snippet ? tagBlock('event', 'Event snippet (conversion page)', tag.event_snippet, 'Paste on the conversion page. For sales, set <code>value</code> and <code>transaction_id</code> from your order.') : ''}
      ${tag.conversion_id ? `<div class="hint">Conversion ID: <b>${esc(tag.conversion_id)}</b>${tag.label ? ` · Label: <b>${esc(tag.label)}</b>` : ''}</div>` : ''}
      <div class="hint">Prefer Google Tag Manager? In GTM add a "Google Ads Conversion Tracking" tag with the Conversion ID and Label above.</div>`;
    body.addEventListener('click', e => {
      const b = e.target.closest('[data-copy]');
      if (!b) return;
      const pre = m.$('#' + b.dataset.copy);
      navigator.clipboard?.writeText(pre.textContent).then(() => { b.innerHTML = `${icon('check', 'sm')} Copied`; setTimeout(() => { b.innerHTML = `${icon('copy', 'sm')} Copy`; }, 1500); });
    });
  }
})();
