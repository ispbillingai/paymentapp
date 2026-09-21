'use strict';
/**
 * The signed-in workspace. Every section is its own page, so this script picks the
 * work for the page it is on from document.body.dataset.page and does nothing else.
 *
 * Anything that issues or replaces a credential asks for the account password
 * first, in a dialog, and shows the new value once. Secrets are never stored in
 * readable form on the server, so they cannot be shown a second time.
 */
(() => {
  const page = document.body.dataset.page || '';
  const el = id => document.getElementById(id);

  // ----------------------------------------------------------------- requests
  async function api(path, options) {
    const settings = Object.assign({credentials: 'same-origin', headers: {}}, options);
    settings.headers = Object.assign({Accept: 'application/json'}, settings.headers);
    if (settings.method && settings.method !== 'GET') {
      settings.headers['Content-Type'] = 'application/json';
      settings.headers['X-Portal-Request'] = '1';
    }
    const response = await fetch(path, settings);
    if (response.status === 401) {
      location.replace('/login');
      throw new Error('Sign in to continue.');
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const problem = new Error((data.error && data.error.message) || 'That request could not be completed.');
      problem.code = data.error && data.error.code;
      throw problem;
    }
    return data;
  }
  const post = (path, payload) => api(path, {method: 'POST', body: JSON.stringify(payload || {})});

  // A pending merchant can finish setup, including key creation. The gateway
  // accepts live traffic only after the email link activates the account.
  function verificationPrompt(data) {
    if (!data.user || data.user.email_verified !== false) return;
    const main = document.querySelector('.portal-main');
    if (!main || el('email-verification-banner')) return;
    const banner = document.createElement('section');
    banner.id = 'email-verification-banner';
    banner.className = 'email-verification-banner';
    banner.setAttribute('aria-label', 'Verify your email to activate live payments');
    banner.innerHTML = '<div><span class="micro-label">ACTION NEEDED</span><h2>Verify your email to go live.</h2><p>Your dashboard is ready. You can create API keys and finish setup now; live payments and listener connections start after verification.</p><small></small></div><button type="button" class="button button-dark button-small">Resend verification email</button>';
    banner.querySelector('small').textContent = 'Account email: ' + data.user.email;
    const result = document.createElement('p');
    result.className = 'email-verification-result';
    result.setAttribute('role', 'status');
    banner.append(result);
    const resend = async button => {
      button.disabled = true;
      result.textContent = 'Sending a new link…';
      try {
        const response = await post('/v1/portal/resend-verification', {email: data.user.email});
        result.textContent = response.message || 'Check your inbox for the new link.';
      } catch (error) {
        result.textContent = error.message;
      } finally {
        button.disabled = false;
      }
    };
    banner.querySelector('button').onclick = event => resend(event.currentTarget);
    const header = main.querySelector('.portal-top');
    if (header) header.insertAdjacentElement('afterend', banner);
    else main.prepend(banner);

    const once = 'isp-pay-verification-prompt:' + data.user.email;
    try { if (sessionStorage.getItem(once)) return; sessionStorage.setItem(once, '1'); } catch (_) {}
    const popup = document.createElement('div');
    popup.className = 'portal-dialog email-verification-popup';
    popup.innerHTML = '<div class="portal-dialog-box" role="dialog" aria-modal="true" aria-labelledby="verify-prompt-title"><p class="micro-label">CHECK YOUR INBOX</p><h2 id="verify-prompt-title">Set up now. Verify to go live.</h2><p class="dialog-detail">Your workspace is open, and you can create API keys. Use the link in your email to activate live payments. If it has not arrived, request another.</p><p class="dialog-detail verify-prompt-address"></p><div class="form-actions"><button type="button" class="button button-outline button-small verify-prompt-close">Continue setup</button><button type="button" class="button button-dark button-small verify-prompt-resend">Resend email</button></div></div>';
    popup.querySelector('.verify-prompt-address').textContent = data.user.email;
    popup.querySelector('.verify-prompt-close').onclick = () => popup.remove();
    popup.querySelector('.verify-prompt-resend').onclick = async event => {
      await resend(event.currentTarget);
      popup.remove();
    };
    popup.addEventListener('keydown', event => { if (event.key === 'Escape') popup.remove(); });
    document.body.append(popup);
    popup.querySelector('.verify-prompt-close').focus();
  }

  // ----------------------------------------------------------------- display
  function money(value, currency) {
    try {
      return new Intl.NumberFormat(undefined, {style: 'currency', currency: currency || 'USD'}).format(Number(value || 0));
    } catch (_) {
      return (currency || '') + ' ' + Number(value || 0).toFixed(2);
    }
  }
  /**
   * Stored times are UTC. They are drawn in the merchant's own zone when one is
   * set, so this page, the listener phone and the billing system all say the same
   * thing about the same moment; otherwise in the reader's own, as before.
   */
  let displayZone = '';
  function when(value) {
    if (!value) return '—';
    const at = new Date(String(value).replace(' ', 'T') + 'Z');
    if (isNaN(at)) return String(value);
    const options = {year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'};
    if (displayZone) {
      try {
        return at.toLocaleString(undefined, Object.assign({timeZone: displayZone}, options));
      } catch (_) {
        // A zone this browser does not know must not blank the whole column.
      }
    }
    return at.toLocaleString(undefined, options);
  }
  const titleCase = text => String(text || '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());

  function show(id, message) {
    const target = el(id);
    if (!target) return;
    target.textContent = message || '';
    target.hidden = !message;
  }
  const problem = message => show('operations-error', message);

  function chip(text, tone) {
    const span = document.createElement('span');
    span.className = 'state-chip' + (tone ? ' ' + tone : '');
    span.textContent = text;
    return span;
  }

  /**
   * A table built from columns, where a column may render a cell itself. Nothing
   * is ever assembled as an HTML string, so a payer's own name cannot become markup.
   */
  function table(target, columns, rows, empty) {
    target.replaceChildren();
    if (!rows.length) {
      const note = document.createElement('div');
      note.className = 'portal-empty';
      const strong = document.createElement('strong');
      strong.textContent = empty.title;
      const text = document.createElement('p');
      text.textContent = empty.body;
      note.append(strong, text);
      target.append(note);
      return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    const element = document.createElement('table');
    const head = document.createElement('thead');
    const headRow = document.createElement('tr');
    columns.forEach(column => {
      const th = document.createElement('th');
      th.textContent = column.label;
      headRow.append(th);
    });
    head.append(headRow);
    const body = document.createElement('tbody');
    rows.forEach(row => {
      const tr = document.createElement('tr');
      columns.forEach(column => {
        const td = document.createElement('td');
        if (column.cell) {
          const made = column.cell(row);
          if (made instanceof Node) td.append(made);
          else td.textContent = made == null ? '—' : String(made);
        } else {
          const value = row[column.key];
          td.textContent = value == null || value === '' ? '—' : String(value);
        }
        tr.append(td);
      });
      body.append(tr);
    });
    element.append(head, body);
    wrap.append(element);
    target.append(wrap);
  }

  // ------------------------------------------------- confirming with a password
  let dialog = null;
  /**
   * Asks for the account password and resolves with it, or null if the person
   * changes their mind. The value is held only for the request that follows.
   */
  function confirmPassword(heading, detail, action) {
    if (!dialog) {
      dialog = document.createElement('div');
      dialog.className = 'portal-dialog';
      dialog.hidden = true;
      dialog.innerHTML = '<div class="portal-dialog-box" role="dialog" aria-modal="true" aria-labelledby="dialog-title">'
        + '<h2 id="dialog-title"></h2><p class="dialog-detail"></p>'
        + '<div class="field"><label for="dialog-password">Account password</label>'
        + '<input id="dialog-password" type="password" autocomplete="current-password"></div>'
        + '<p class="form-error dialog-error" role="alert" hidden></p>'
        + '<div class="form-actions"><button type="button" class="button button-outline button-small dialog-cancel">Cancel</button>'
        + '<button type="button" class="button button-dark button-small dialog-confirm"></button></div></div>';
      document.body.append(dialog);
    }
    const input = dialog.querySelector('#dialog-password');
    const error = dialog.querySelector('.dialog-error');
    dialog.querySelector('#dialog-title').textContent = heading;
    dialog.querySelector('.dialog-detail').textContent = detail;
    dialog.querySelector('.dialog-confirm').textContent = action;
    input.value = '';
    error.hidden = true;
    dialog.hidden = false;
    input.focus();

    return new Promise(resolve => {
      const close = value => {
        dialog.hidden = true;
        input.value = '';
        dialog.removeEventListener('keydown', onKey);
        resolve(value);
      };
      const submit = () => {
        if (!input.value) {
          error.textContent = 'Enter your account password.';
          error.hidden = false;
          input.focus();
          return;
        }
        close(input.value);
      };
      const onKey = event => {
        if (event.key === 'Escape') close(null);
        if (event.key === 'Enter' && event.target === input) { event.preventDefault(); submit(); }
      };
      dialog.querySelector('.dialog-cancel').onclick = () => close(null);
      dialog.querySelector('.dialog-confirm').onclick = submit;
      dialog.addEventListener('keydown', onKey);
    });
  }

  /**
   * Asks a plain yes or no, for something that issues no credential and so has
   * no password to confirm. Resolves true only if they said yes.
   */
  let askBox = null;
  function confirmAction(heading, detail, action) {
    if (!askBox) {
      askBox = document.createElement('div');
      askBox.className = 'portal-dialog';
      askBox.hidden = true;
      askBox.innerHTML = '<div class="portal-dialog-box" role="dialog" aria-modal="true" aria-labelledby="ask-title">'
        + '<h2 id="ask-title"></h2><p class="dialog-detail"></p>'
        + '<div class="form-actions"><button type="button" class="button button-outline button-small ask-cancel">Cancel</button>'
        + '<button type="button" class="button button-dark button-small ask-confirm"></button></div></div>';
      document.body.append(askBox);
    }
    askBox.querySelector('#ask-title').textContent = heading;
    askBox.querySelector('.dialog-detail').textContent = detail;
    const yes = askBox.querySelector('.ask-confirm');
    yes.textContent = action;
    askBox.hidden = false;
    yes.focus();
    return new Promise(resolve => {
      const close = value => {
        askBox.hidden = true;
        askBox.removeEventListener('keydown', onKey);
        resolve(value);
      };
      const onKey = event => { if (event.key === 'Escape') close(false); };
      askBox.querySelector('.ask-cancel').onclick = () => close(false);
      yes.onclick = () => close(true);
      askBox.addEventListener('keydown', onKey);
    });
  }

  /** Reveals a value once, and wires its copy button. */
  function reveal(panelId, valueId, value) {
    el(valueId).textContent = value;
    el(panelId).hidden = false;
    el(panelId).scrollIntoView({behavior: 'smooth', block: 'center'});
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const text = el(button.dataset.copy).textContent;
    const done = () => { const was = button.textContent; button.textContent = 'Copied'; setTimeout(() => { button.textContent = was; }, 1600); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, () => {});
    else done();
  });

  // ----------------------------------------------------------------- sign out
  const signOut = el('portal-logout');
  if (signOut) {
    signOut.addEventListener('click', async () => {
      try { await post('/v1/portal/logout'); } catch (_) {}
      location.replace('/login');
    });
  }

  /**
   * The signed-in account, shown in the corner of every page. Navigation entries
   * meant for one role only are revealed here, once the role is known.
   */
  async function identify() {
    try {
      const data = await api('/v1/portal/account');
      const badge = el('portal-user');
      if (badge) badge.textContent = data.user.email;
      document.querySelectorAll('.portal-nav a[data-role]').forEach(link => {
        link.hidden = link.dataset.role !== data.user.role;
      });
      if (data.merchant && data.merchant.timezone) displayZone = data.merchant.timezone;
      viewingBanner(data.acting_as);
      shell(data);
      verificationPrompt(data);
      return data;
    } catch (e) {
      problem(e.message);
      return null;
    }
  }

  // ------------------------------------------------------------ the drawer
  // On a narrow screen the sidebar slides in from the menu button in the top bar.
  (() => {
    const menu = el('portal-menu');
    const scrim = el('portal-scrim');
    if (!menu || !scrim) return;
    const set = open => {
      document.body.classList.toggle('nav-open', open);
      menu.setAttribute('aria-expanded', open ? 'true' : 'false');
      scrim.hidden = !open;
    };
    menu.addEventListener('click', () => set(!document.body.classList.contains('nav-open')));
    scrim.addEventListener('click', () => set(false));
    // The name in the bar opens the drawer too, since the view switch lives at its top.
    const scopeButton = el('portal-bar-scope');
    if (scopeButton) scopeButton.addEventListener('click', () => set(true));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') set(false); });
  })();

  /**
   * Fills in the parts of the sidebar that depend on who is signed in: the person
   * at the bottom, and at the top whose workspace is on screen. For a platform
   * owner that top block is the switch between the whole gateway and one merchant.
   */
  let shellDone = false;
  async function shell(data) {
    if (shellDone || !el('scope-switch')) return;
    shellDone = true;
    const initial = text => (String(text || '?').trim().charAt(0) || '?').toUpperCase();
    el('user-email').textContent = data.user.email;
    el('user-role').textContent = data.user.role === 'owner' ? 'Platform owner' : titleCase(data.user.role);
    el('user-avatar').textContent = initial(data.user.email);

    const owner = data.user.role === 'owner';
    const name = data.acting_as ? data.acting_as.name
      : owner ? 'All merchants'
      : data.merchant ? data.merchant.name : 'Developer account';
    el('scope-name').textContent = name;
    el('scope-kind').textContent = data.acting_as ? (data.acting_as.own ? 'MY MERCHANT ACCOUNT' : 'VIEWING AS')
      : owner ? 'PLATFORM OWNER' : 'WORKSPACE';
    el('scope-avatar').textContent = owner && !data.acting_as ? '∗' : initial(name);
    el('scope-switch').classList.toggle('acting', !!data.acting_as);
    const label = document.querySelector('.portal-top .micro-label');
    if (label && owner && !data.acting_as) label.textContent = 'PLATFORM OWNER';
    const bar = el('portal-bar-scope');
    if (bar) bar.textContent = name;
    if (!owner) return;

    // The owner's switch: the whole gateway, or any one merchant as they see it.
    const button = el('scope-current');
    const menu = el('scope-menu');
    el('scope-chevron').hidden = false;
    button.disabled = false;
    let merchants = [];
    try {
      merchants = (await api('/v1/portal/merchants')).merchants;
    } catch (_) {}
    const entry = (label, detail, id, current) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.setAttribute('role', 'menuitem');
      item.className = 'scope-item' + (current ? ' current' : '');
      const avatar = document.createElement('span');
      avatar.className = 'scope-avatar';
      avatar.textContent = id ? initial(label) : '∗';
      const text = document.createElement('span');
      text.className = 'scope-text';
      const strong = document.createElement('b');
      strong.textContent = label;
      const small = document.createElement('small');
      small.textContent = detail;
      text.append(strong, small);
      item.append(avatar, text);
      item.onclick = async () => {
        if (current) { close(); return; }
        item.disabled = true;
        try { await viewAs(id); } catch (e) { problem(e.message); item.disabled = false; }
      };
      return item;
    };
    const heading = text => {
      const node = document.createElement('p');
      node.className = 'scope-heading';
      node.textContent = text;
      return node;
    };
    const isCurrent = id => !!data.acting_as && data.acting_as.id === id;
    menu.append(heading('Platform'), entry('All merchants', 'The whole gateway, as its owner', '', !data.acting_as));

    // The owner's own merchant account, on this same sign-in.
    menu.append(heading('My account'));
    const own = data.own_merchant;
    if (own) {
      menu.append(entry(own.name, 'Your own payments, keys and webhook', own.id, isCurrent(own.id)));
    } else {
      const setUp = document.createElement('button');
      setUp.type = 'button';
      setUp.className = 'scope-add first';
      setUp.textContent = 'Open my merchant dashboard →';
      setUp.onclick = () => { close(); openMyMerchant(); };
      menu.append(setUp);
    }

    const others = merchants.filter(merchant => !own || merchant.id !== own.id);
    if (others.length) menu.append(heading('Other merchants'));
    others.forEach(merchant => menu.append(entry(merchant.name,
      titleCase(merchant.country) + ' · ' + merchant.currency + ' · as this merchant sees it',
      merchant.id, isCurrent(merchant.id))));
    const add = document.createElement('a');
    add.className = 'scope-add';
    add.href = '/dashboard/merchants';
    add.textContent = '+ Add a merchant';
    menu.append(add);

    const close = () => { menu.hidden = true; button.setAttribute('aria-expanded', 'false'); };
    button.addEventListener('click', event => {
      event.stopPropagation();
      menu.hidden = !menu.hidden;
      button.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', event => { if (!menu.contains(event.target)) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  }

  /**
   * While an owner is looking at one merchant's workspace, every page says so and
   * offers the way back, so what is on screen is never mistaken for the whole gateway.
   */
  function viewingBanner(acting) {
    const existing = document.querySelector('.viewing-banner');
    if (existing) existing.remove();
    if (!acting) return;
    const banner = document.createElement('div');
    banner.className = 'viewing-banner';
    const text = document.createElement('p');
    const label = document.createElement('strong');
    label.textContent = acting.name;
    if (acting.own) {
      text.append(document.createTextNode('You are in your own merchant account, '), label,
        document.createTextNode('. Keys, listeners, webhook and payments here are yours.'));
    } else {
      text.append(document.createTextNode('Viewing as '), label,
        document.createTextNode(' · you are seeing only this merchant’s payments, listeners and keys.'));
    }
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'button button-outline button-small';
    back.textContent = 'Back to owner view';
    back.onclick = async () => {
      back.disabled = true;
      try {
        await post('/v1/portal/view-as', {merchant_id: ''});
        location.reload();
      } catch (e) {
        problem(e.message);
        back.disabled = false;
      }
    };
    banner.append(text, back);
    const main = document.querySelector('.portal-main');
    const header = main && main.querySelector('.portal-top');
    if (header) header.insertAdjacentElement('afterend', banner);
    else if (main) main.prepend(banner);
  }

  /**
   * An owner opening a merchant account of their own, on the same sign-in. It asks
   * for the business and its country, and they are on that account's dashboard. It
   * opens in place, from wherever they are: being sent to a settings page to find a
   * form is not what anyone expects on the way into their dashboard.
   */
  function openMyMerchant() {
    const existing = document.querySelector('.portal-dialog.my-merchant');
    if (existing) existing.remove();
    const dialogBox = document.createElement('div');
    dialogBox.className = 'portal-dialog my-merchant';
    dialogBox.innerHTML = '<form class="portal-dialog-box" role="dialog" aria-modal="true" aria-labelledby="mine-title" novalidate>'
      + '<h2 id="mine-title">Open your merchant account</h2>'
      + '<p class="dialog-detail">Same email and password. You get your own dashboard, keys, listener phones, webhook and payments, and you switch back to the owner view from the top of the sidebar.</p>'
      + '<div class="field"><label for="mine-name">Business or ISP name</label><input id="mine-name" type="text" maxlength="120" autocomplete="organization" required></div>'
      + '<div class="field"><label for="mine-country">Country</label><select id="mine-country" required><option value="">Choose your country</option></select></div>'
      + '<div class="field" id="mine-other-field" hidden><label for="mine-other">Country name</label><input id="mine-other" type="text" maxlength="40"></div>'
      + '<div class="field-row"><div class="field"><label for="mine-dial">Calling code</label><input id="mine-dial" type="text" inputmode="numeric" maxlength="8" placeholder="233" required></div>'
      + '<div class="field"><label for="mine-currency">Currency</label><input id="mine-currency" type="text" maxlength="3" placeholder="GHS" required></div></div>'
      + '<p class="form-error" role="alert" hidden></p>'
      + '<div class="form-actions"><button type="button" class="button button-outline button-small dialog-cancel">Cancel</button>'
      + '<button type="submit" class="button button-dark button-small">Open my dashboard</button></div></form>';
    document.body.append(dialogBox);
    document.body.classList.remove('nav-open');
    const scrim = el('portal-scrim');
    if (scrim) scrim.hidden = true;

    const find = selector => dialogBox.querySelector(selector);
    const country = find('#mine-country');
    (window.ISPPayCountries || []).forEach(item => {
      const option = document.createElement('option');
      option.value = item[1].toLowerCase();
      option.textContent = item[1];
      option.dataset.dial = item[2];
      option.dataset.currency = item[3];
      country.append(option);
    });
    const elsewhere = document.createElement('option');
    elsewhere.value = 'other';
    elsewhere.textContent = 'Another country';
    country.append(elsewhere);
    country.onchange = () => {
      const typed = country.value === 'other';
      find('#mine-other-field').hidden = !typed;
      const chosen = country.options[country.selectedIndex];
      find('#mine-dial').value = typed ? '' : (chosen.dataset.dial || '');
      find('#mine-currency').value = typed ? '' : (chosen.dataset.currency || '');
    };

    const error = find('.form-error');
    const close = () => dialogBox.remove();
    find('.dialog-cancel').onclick = close;
    dialogBox.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    find('form').addEventListener('submit', async event => {
      event.preventDefault();
      error.hidden = true;
      const name = find('#mine-name').value.trim();
      const where = country.value === 'other' ? find('#mine-other').value.trim().toLowerCase() : country.value;
      const dial = find('#mine-dial').value.replace(/[^0-9]/g, '');
      const currency = find('#mine-currency').value.trim().toUpperCase();
      if (!name || !where || !dial || currency.length !== 3) {
        error.textContent = 'Enter your business name, choose your country, and check the calling code and currency.';
        error.hidden = false;
        return;
      }
      const button = find('button[type=submit]');
      button.disabled = true;
      button.textContent = 'Opening…';
      try {
        const made = await post('/v1/portal/my-merchant', {name, country: where, dial_code: dial, currency});
        location.assign(made.next || '/dashboard');
      } catch (e) {
        error.textContent = e.message;
        error.hidden = false;
        button.disabled = false;
        button.textContent = 'Open my dashboard';
      }
    });
    find('#mine-name').focus();
  }

  /** Switches the workspace to one merchant's view, or back to the whole gateway. */
  async function viewAs(merchantId) {
    await post('/v1/portal/view-as', {merchant_id: merchantId || ''});
    location.assign('/dashboard');
  }

  /**
   * A platform owner acts for a named merchant, so pages that issue credentials
   * offer a chooser. For everyone else there is nothing to choose and nothing shown.
   */
  async function merchantScope(account, into, note) {
    const scope = {id: null, ready: false, owner: account && account.user.role === 'owner'};
    if (!scope.owner) {
      scope.ready = !!(account && account.merchant);
      scope.id = null;
      return scope;
    }
    // Inside one merchant's workspace, their own or another's, that merchant is the
    // only target. Offering a chooser there would let a key land on someone else.
    if (account.acting_as) {
      scope.id = account.acting_as.id;
      scope.ready = true;
      scope.owner = false;
      return scope;
    }
    let merchants = [];
    try {
      merchants = (await api('/v1/portal/merchants')).merchants;
    } catch (e) {
      problem(e.message);
    }
    const holder = el(into);
    if (!holder) return scope;
    holder.hidden = false;
    if (!merchants.length) {
      const empty = document.createElement('p');
      empty.className = 'scope-empty';
      empty.textContent = note;
      holder.append(empty);
      return scope;
    }
    const label = document.createElement('label');
    label.setAttribute('for', 'scope-merchant');
    label.textContent = 'Acting for merchant';
    const select = document.createElement('select');
    select.id = 'scope-merchant';
    merchants.forEach(merchant => {
      const option = document.createElement('option');
      option.value = merchant.id;
      option.textContent = merchant.name + ' · ' + merchant.currency;
      select.append(option);
    });
    holder.append(label, select);
    scope.id = select.value;
    scope.ready = true;
    select.onchange = () => {
      scope.id = select.value;
      if (scope.onchange) scope.onchange();
    };
    return scope;
  }

  // ------------------------------------------------------------------ charts
  const SVG = 'http://www.w3.org/2000/svg';
  const svgEl = (name, attributes) => {
    const node = document.createElementNS(SVG, name);
    Object.keys(attributes || {}).forEach(key => node.setAttribute(key, attributes[key]));
    return node;
  };

  /**
   * Daily payments as stacked columns, matched beneath and held above, with a
   * value axis and a hover label. Drawn here rather than loaded from a chart
   * library, so the page stays within its own origin and loads nothing extra.
   */
  function columnChart(target, series, currency) {
    target.replaceChildren();
    // Drawn at the width it is shown at, not scaled down from a desktop size, so
    // the axis text is as readable on a phone as it is on a wide screen.
    const width = Math.max(300, Math.round(target.clientWidth || 960));
    const narrow = width < 560;
    const height = narrow ? 220 : 260;
    const padLeft = narrow ? 30 : 58;
    const padRight = 12;
    const padTop = 14;
    const padBottom = 30;
    const plotWidth = width - padLeft - padRight;
    const plotHeight = height - padTop - padBottom;
    const peak = Math.max(1, ...series.map(point => point.count));
    // A rounded ceiling keeps the gridline labels whole numbers.
    const step = Math.max(1, Math.ceil(peak / 4));
    const ceiling = step * Math.max(1, Math.ceil(peak / step));
    const scale = value => plotHeight - (value / ceiling) * plotHeight;

    const svg = svgEl('svg', {viewBox: '0 0 ' + width + ' ' + height, class: 'chart', role: 'img',
      'aria-label': 'Payments received per day over the last ' + series.length + ' days'});
    const plot = svgEl('g', {transform: 'translate(' + padLeft + ',' + padTop + ')'});

    for (let value = 0; value <= ceiling; value += step) {
      const y = scale(value);
      plot.append(svgEl('line', {x1: 0, x2: plotWidth, y1: y, y2: y, class: 'grid'}));
      const label = svgEl('text', {x: -10, y: y + 4, class: 'axis', 'text-anchor': 'end'});
      label.textContent = value;
      plot.append(label);
    }

    const slot = plotWidth / series.length;
    const barWidth = Math.max(2, Math.min(26, slot - (narrow ? 2 : 4)));
    const readable = day => new Date(day + 'T00:00:00Z').toLocaleDateString(undefined, {month: 'short', day: 'numeric'});
    series.forEach((point, index) => {
      const x = index * slot + (slot - barWidth) / 2;
      const group = svgEl('g', {class: 'column'});
      const stack = [['matched', point.matched], ['pending', point.pending]];
      let base = 0;
      stack.forEach(([tone, value]) => {
        if (!value) return;
        const top = scale(base + value);
        const bottom = scale(base);
        group.append(svgEl('rect', {x: x, y: top, width: barWidth, height: Math.max(1, bottom - top), rx: 2, class: 'bar ' + tone}));
        base += value;
      });
      if (!point.count) {
        group.append(svgEl('rect', {x: x, y: plotHeight - 2, width: barWidth, height: 2, rx: 1, class: 'bar empty'}));
      }
      const title = svgEl('title');
      title.textContent = readable(point.day) + ' · ' + point.count + ' payment' + (point.count === 1 ? '' : 's')
        + (point.count ? ' · ' + money(point.total, currency) : '')
        + (point.pending ? ' · ' + point.pending + ' needing review' : '');
      group.append(title);
      plot.append(group);
    });

    // Only as many dates as fit are labelled, counted back from today so the
    // newest day is always named and two labels never land on top of each other.
    const every = Math.max(1, Math.ceil(series.length / Math.max(2, Math.floor(plotWidth / 78))));
    series.forEach((point, index) => {
      if ((series.length - 1 - index) % every) return;
      const label = svgEl('text', {x: index * slot + slot / 2, y: plotHeight + 20, class: 'axis', 'text-anchor': 'middle'});
      label.textContent = readable(point.day);
      plot.append(label);
    });

    svg.append(plot);
    target.append(svg);
  }

  /** A ranked list with a proportional bar behind each row. */
  function barList(target, rows, labelOf, valueOf, empty) {
    target.replaceChildren();
    if (!rows.length) {
      const note = document.createElement('p');
      note.className = 'portal-empty quiet';
      note.textContent = empty;
      target.append(note);
      return;
    }
    const peak = Math.max(...rows.map(valueOf), 1);
    const list = document.createElement('ul');
    list.className = 'bar-list';
    rows.forEach(row => {
      const item = document.createElement('li');
      const name = document.createElement('span');
      name.className = 'bar-label';
      name.textContent = labelOf(row);
      const track = document.createElement('span');
      track.className = 'bar-track';
      const fill = document.createElement('i');
      fill.style.width = Math.max(2, (valueOf(row) / peak) * 100) + '%';
      track.append(fill);
      const value = document.createElement('b');
      value.textContent = valueOf(row);
      item.append(name, track, value);
      list.append(item);
    });
    target.append(list);
  }

  // ===================================================================== pages
  const pages = {};

  // ---------------------------------------------------------------- overview
  pages['dashboard'] = async () => {
    let data;
    try {
      data = await api('/v1/portal/overview');
    } catch (e) {
      el('portal-loading').textContent = e.message;
      return;
    }
    // An owner looking at one merchant reads the page as that merchant would.
    const owner = data.user.role === 'owner' && !data.acting_as;
    identify();
    el('portal-greeting').textContent = owner ? 'Gateway overview' : (data.merchant && data.merchant.name ? data.merchant.name : 'Overview');
    el('metric-total').textContent = data.totals.length === 1
      ? money(data.totals[0].total, data.totals[0].currency)
      : (data.totals.length ? 'Several currencies' : money(0, data.metrics.currency));
    if (data.totals.length > 1) {
      data.totals.forEach(total => {
        const line = document.createElement('small');
        line.textContent = money(total.total, total.currency);
        el('currency-totals').append(line);
      });
    }
    el('metric-matched').textContent = data.metrics.matched;
    el('metric-review').textContent = data.metrics.unmatched;
    el('metric-devices').textContent = data.metrics.devices;
    el('metric-online').textContent = data.metrics.online + ' reporting now';

    const list = el('portal-payments');
    if (!data.payments.length) {
      const note = document.createElement('div');
      note.className = 'portal-empty';
      const strong = document.createElement('strong');
      strong.textContent = 'No payments yet';
      const text = document.createElement('p');
      text.textContent = 'Receipts appear here as soon as a listener starts reporting them.';
      note.append(strong, text);
      list.append(note);
    } else {
      data.payments.forEach(payment => {
        const row = document.createElement('div');
        row.className = 'portal-payment-row';
        const avatar = document.createElement('span');
        avatar.className = 'payment-avatar';
        avatar.textContent = '↙';
        const who = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = payment.payer_name || payment.payer_msisdn || 'Customer';
        const meta = document.createElement('small');
        meta.textContent = payment.trx_id + ' · ' + when(payment.received_at);
        who.append(name, meta);
        const amount = document.createElement('b');
        amount.textContent = money(payment.amount, payment.currency);
        const state = document.createElement('em');
        state.className = 'status ' + (payment.status === 'matched' ? 'matched' : 'review');
        state.textContent = payment.status === 'matched' ? 'Matched' : 'Needs review';
        row.append(avatar, who, amount, state);
        list.append(row);
      });
    }

    // Where customers should send money. Nothing to show before a listener exists.
    const payTo = el('pay-to-summary');
    if (data.merchant && data.metrics.devices) {
      const heading = document.createElement('h2');
      heading.textContent = 'Customers pay';
      payTo.append(heading);
      const note = document.createElement('p');
      note.textContent = 'Shown to your customers on the payment page.';
      payTo.append(note);
    } else if (!owner) {
      const note = document.createElement('p');
      note.textContent = 'Add a listener device to publish the number your customers should pay.';
      payTo.append(note);
    } else {
      const note = document.createElement('p');
      note.textContent = 'Each merchant configures its own receiving account.';
      payTo.append(note);
    }

    const hasWebhook = !!(data.merchant && data.merchant.webhook_url);
    el('health-webhook').classList.toggle('warn', !owner && !hasWebhook);
    el('health-device').classList.toggle('warn', data.metrics.devices === 0);
    el('health-key').classList.toggle('warn', !owner && !(data.metrics.keys > 0));
    el('integration-title').textContent = data.metrics.devices ? 'Your integration is connected.' : 'Finish listener setup.';
    el('health-webhook-label').textContent = owner ? 'Webhooks are set per merchant'
      : (hasWebhook ? 'Webhook address configured' : 'No webhook address yet');
    el('health-device-label').textContent = data.metrics.devices
      ? data.metrics.online + ' of ' + data.metrics.devices + ' listeners reporting'
      : 'No listener configured yet';
    el('health-key-label').textContent = owner ? 'Keys are issued per merchant'
      : (data.metrics.keys > 0 ? data.metrics.keys + ' live API key' + (data.metrics.keys === 1 ? '' : 's') + ' active'
        : data.merchant ? 'No API key yet' : 'No live merchant account yet');

    // A short checklist, only while something is actually outstanding.
    if (!owner) {
      const steps = data.merchant ? [
        {done: data.metrics.keys > 0, label: 'Create your API key', href: '/dashboard/developers'},
        {done: data.metrics.devices > 0, label: 'Pair your listener phone', href: '/dashboard/devices'},
        {done: hasWebhook, label: 'Point your webhook at your billing system', href: '/dashboard/developers'},
      ] : [
        {done: false, label: 'Create your live merchant account', href: '/signup'},
      ];
      const outstanding = steps.filter(step => !step.done).length;
      if (outstanding) {
        el('setup-progress').textContent = (steps.length - outstanding) + ' OF ' + steps.length + ' DONE';
        const target = el('setup-steps');
        steps.forEach(step => {
          const item = document.createElement('li');
          item.className = step.done ? 'done' : '';
          const mark = document.createElement('span');
          mark.className = 'step-mark';
          mark.textContent = step.done ? '✓' : '';
          const link = document.createElement(step.done ? 'span' : 'a');
          if (!step.done) link.href = step.href;
          link.textContent = step.label;
          item.append(mark, link);
          target.append(item);
        });
        el('portal-setup').hidden = false;
      }
    }
    el('portal-loading').hidden = true;
    el('portal-content').hidden = false;

    // The charts load after the page is usable, so a slow aggregate never holds it up.
    const range = document.querySelector('.chart-range');
    async function drawCharts(days) {
      try {
        const insight = await api('/v1/portal/insights?days=' + days);
        el('chart-heading').textContent = 'Last ' + insight.days + ' days';
        columnChart(el('chart-trend'), insight.series, insight.currency);
        barList(el('chart-providers'), insight.providers, row => row.label || row.provider, row => Number(row.count),
          'No payments recorded in this period.');
        barList(el('chart-holds'), insight.holds, row => row.hold_reason ? titleCase(row.hold_reason) : 'Awaiting a match',
          row => Number(row.count), 'Nothing is waiting for review.');
      } catch (e) {
        problem(e.message);
      }
    }
    let days = 30;
    range.addEventListener('click', event => {
      const button = event.target.closest('button[data-days]');
      if (!button) return;
      range.querySelectorAll('button').forEach(other => other.classList.toggle('active', other === button));
      days = button.dataset.days;
      drawCharts(days);
    });
    // Turning a phone, or resizing the window, changes the width the chart is drawn for.
    let resizing;
    let lastWidth = window.innerWidth;
    window.addEventListener('resize', () => {
      if (window.innerWidth === lastWidth) return;
      lastWidth = window.innerWidth;
      clearTimeout(resizing);
      resizing = setTimeout(() => drawCharts(days), 200);
    });
    drawCharts(days);
  };

  // --------------------------------------------------------------- merchants
  pages['dashboard-merchants'] = async () => {
    await identify();
    const columns = [
      {label: 'Business', key: 'name'},
      {label: 'Merchant ID', key: 'id'},
      {label: 'Country', cell: row => titleCase(row.country) + ' · +' + row.dial_code},
      {label: 'Currency', key: 'currency'},
      {label: 'Listeners', key: 'devices'},
      {label: 'Active keys', key: 'keys_active'},
      {label: 'Payments', key: 'payments'},
      {label: 'Added', cell: row => when(row.created_at)},
      {
        label: '',
        cell: row => {
          const open = document.createElement('button');
          open.type = 'button';
          open.className = 'copy-button';
          open.textContent = 'View as';
          open.onclick = async () => {
            open.disabled = true;
            try {
              await viewAs(row.id);
            } catch (e) {
              problem(e.message);
              open.disabled = false;
            }
          };
          return open;
        },
      },
    ];
    async function load() {
      try {
        const data = await api('/v1/portal/merchants');
        table(el('merchant-records'), columns, data.merchants, {
          title: 'No merchants yet',
          body: 'Add the first one above. Its credentials are shown once, straight after it is created.',
        });
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }
    el('merchants-refresh').onclick = () => load();
    el('merchant-form').addEventListener('submit', async event => {
      event.preventDefault();
      show('merchant-form-error', '');
      const password = await confirmPassword('Create this merchant',
        'The webhook address is contacted now and has to answer the verification request before the account is created.',
        'Verify and create');
      if (!password) return;
      const button = el('merchant-form').querySelector('button[type=submit]');
      const was = button.textContent;
      button.disabled = true;
      button.textContent = 'Verifying…';
      try {
        const data = await post('/v1/portal/merchants', {
          name: el('merchant-name').value.trim(),
          country: el('merchant-country').value.trim().toLowerCase(),
          dial_code: el('merchant-dial').value.trim(),
          currency: el('merchant-currency').value.trim().toUpperCase(),
          webhook_url: el('merchant-webhook').value.trim(),
          password,
        });
        el('new-merchant-id').textContent = data.merchant_id;
        el('new-merchant-key').textContent = data.api_key;
        el('new-merchant-secret').textContent = data.webhook_secret;
        el('merchant-reveal').hidden = false;
        el('merchant-reveal').scrollIntoView({behavior: 'smooth', block: 'center'});
        el('merchant-form').reset();
        load();
      } catch (e) {
        show('merchant-form-error', e.message);
      } finally {
        button.disabled = false;
        button.textContent = was;
      }
    });
    load();
  };

  // ---------------------------------------------------------------- payments
  pages['dashboard-payments'] = () => {
    identify();
    let current = [];
    let next = null;
    const columns = [
      {label: 'Received', cell: row => when(row.received_at)},
      {label: 'Payer', cell: row => row.payer_name || row.payer_msisdn || '—'},
      {label: 'Amount', cell: row => money(row.amount, row.currency)},
      {label: 'Reference', key: 'reference'},
      {label: 'Transaction', key: 'trx_id'},
      {
        label: 'Status',
        cell: row => {
          if (Number(row.reversed)) return chip('Reversed', 'bad');
          if (row.status === 'matched') return chip('Matched', 'good');
          return chip(row.hold_reason ? titleCase(row.hold_reason) : 'Needs review', 'warn');
        },
      },
    ];

    async function load(before) {
      try {
        const query = new URLSearchParams();
        if (el('payments-filter').value) query.set('status', el('payments-filter').value);
        if (el('payments-search').value.trim()) query.set('q', el('payments-search').value.trim());
        if (before) query.set('before', before);
        const data = await api('/v1/portal/payments?' + query.toString());
        current = data.payments;
        next = data.next_before;
        table(el('payment-records'), columns, current, {
          title: 'No payments here',
          body: 'Nothing matches this filter yet. Clear the search, or wait for the next receipt to arrive.',
        });
        el('payment-next').hidden = !next;
        el('payment-export').disabled = !current.length;
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }

    // The status can be linked to from the overview, so the URL sets it first.
    const wanted = new URLSearchParams(location.search).get('status');
    if (wanted === 'matched' || wanted === 'unmatched') el('payments-filter').value = wanted;

    el('payments-filter').onchange = () => load();
    el('payment-next').onclick = () => load(next);
    let typing;
    el('payments-search').oninput = () => {
      clearTimeout(typing);
      typing = setTimeout(() => load(), 250);
    };
    el('payment-export').onclick = () => {
      const keys = ['public_id', 'received_at', 'payer_name', 'payer_msisdn', 'amount', 'currency', 'reference', 'trx_id', 'provider', 'status', 'reversed'];
      // A leading =, + or @ would be read as a formula by a spreadsheet, so it is quoted away.
      const cell = value => '"' + String(value == null ? '' : value).replace(/^[=+@\-\t\r\n]/, m => "'" + m).replace(/"/g, '""') + '"';
      const csv = [keys.map(cell).join(','), ...current.map(row => keys.map(key => cell(row[key])).join(','))].join('\r\n');
      const url = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
      const link = document.createElement('a');
      link.href = url;
      link.download = 'isp-payments.csv';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    load();
  };

  // --------------------------------------------------------------- messages
  pages['dashboard-messages'] = async () => {
    const account = await identify();
    await merchantScope(account, 'scope-picker', 'No merchants yet. Add one under Merchants first.');
    let next = null;

    const tone = outcome => outcome === 'recorded' ? 'good'
      : outcome === 'duplicate' || outcome === 'reversal' ? 'warn' : 'bad';
    const wording = {
      recorded: 'Became a payment',
      duplicate: 'Already seen',
      reversal: 'Reversal',
      unknown_sender: 'Sender not accepted',
      not_a_payment: 'Not a payment',
      ignored: 'Could not be read',
    };

    /** One message: the text as it arrived, then what was read out of it. */
    function card(row) {
      const item = document.createElement('article');
      item.className = 'message-card';

      const head = document.createElement('div');
      head.className = 'message-head';
      const who = document.createElement('b');
      who.textContent = row.sender || 'Unknown sender';
      const at = document.createElement('small');
      at.textContent = when(row.received_at);
      head.append(who, at, chip(wording[row.outcome] || titleCase(row.outcome), tone(row.outcome)));
      item.append(head);

      // The message itself, unaltered. Never as markup: this text came from outside.
      const body = document.createElement('pre');
      body.className = 'message-body';
      body.textContent = row.body || '';
      item.append(body);

      const read = [];
      if (row.read_trx) read.push(['Transaction', row.read_trx]);
      if (Number(row.read_amount) > 0) read.push(['Amount', money(row.read_amount, row.read_currency)]);
      if (row.read_name) read.push(['Payer', row.read_name]);
      if (row.read_msisdn) read.push(['Number', row.read_msisdn]);
      const foot = document.createElement('div');
      foot.className = 'message-read';
      if (!read.length) {
        const none = document.createElement('small');
        none.textContent = 'Nothing could be read out of this message.';
        foot.append(none);
      } else {
        read.forEach(([label, value]) => {
          const pair = document.createElement('span');
          const key = document.createElement('small');
          key.textContent = label;
          const val = document.createElement('b');
          val.textContent = value;
          pair.append(key, val);
          foot.append(pair);
        });
      }
      item.append(foot);

      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'copy-button';
      copy.textContent = 'Copy message';
      copy.onclick = () => {
        const done = () => { copy.textContent = 'Copied'; setTimeout(() => { copy.textContent = 'Copy message'; }, 1600); };
        if (navigator.clipboard) navigator.clipboard.writeText(row.body || '').then(done, () => {});
        else done();
      };
      const actions = document.createElement('div');
      actions.className = 'message-actions';
      actions.append(copy);
      item.append(actions);
      return item;
    }

    async function load(before) {
      try {
        const query = new URLSearchParams();
        if (el('messages-filter').value) query.set('outcome', el('messages-filter').value);
        if (el('messages-search').value.trim()) query.set('q', el('messages-search').value.trim());
        if (before) query.set('before', before);
        const data = await api('/v1/portal/messages?' + query.toString());
        next = data.next_before;
        const target = el('message-records');
        if (!before) target.replaceChildren();
        if (!data.messages.length && !before) {
          const note = document.createElement('div');
          note.className = 'portal-empty';
          const strong = document.createElement('strong');
          strong.textContent = 'No messages yet';
          const text = document.createElement('p');
          text.textContent = 'Everything a listener phone reports appears here, whether or not it became a payment.';
          note.append(strong, text);
          target.append(note);
        }
        data.messages.forEach(row => target.append(card(row)));
        el('messages-next').hidden = !next;
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }

    el('messages-filter').onchange = () => load();
    el('messages-refresh').onclick = () => load();
    el('messages-next').onclick = () => load(next);
    let typing;
    el('messages-search').oninput = () => { clearTimeout(typing); typing = setTimeout(() => load(), 250); };
    load();
  };

  // ---------------------------------------------------------------- devices
  pages['dashboard-devices'] = async () => {
    const account = await identify();
    const scope = await merchantScope(account, 'scope-picker',
      'No merchants yet. Add one under Merchants before pairing a listener.');
    const select = el('device-provider');

    /**
     * Which networks are built in depends on the merchant's dialling code, so the
     * list is rebuilt whenever the merchant being acted for changes.
     */
    async function loadProviders() {
      select.replaceChildren();
      let providers = (account && account.providers) || [];
      if (scope.owner && scope.id) {
        const chosen = merchantsById[scope.id];
        providers = chosen ? await sendersFor(chosen) : [];
      }
      if (!providers.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = scope.owner ? 'Choose a merchant first' : 'Complete live registration to add a listener';
        select.append(option);
        select.disabled = true;
        el('device-form').querySelector('button[type=submit]').disabled = true;
        return;
      }
      select.disabled = false;
      el('device-form').querySelector('button[type=submit]').disabled = false;
      providers.forEach(provider => {
        const option = document.createElement('option');
        option.value = provider.code;
        option.textContent = provider.label;
        select.append(option);
      });
    }

    // An owner picks the merchant, so their networks have to be looked up per merchant.
    const merchantsById = {};
    async function sendersFor(merchant) {
      const data = await api('/v1/portal/senders?merchant_id=' + encodeURIComponent(merchant.id)).catch(() => null);
      return data ? data.providers : [];
    }
    if (scope.owner) {
      try {
        (await api('/v1/portal/merchants')).merchants.forEach(merchant => { merchantsById[merchant.id] = merchant; });
      } catch (_) {}
    }
    await loadProviders();
    // Naming a sender yourself only applies to a network that is not built in.
    select.onchange = () => { el('device-sender-field').hidden = select.value !== 'other'; };

    /**
     * The numbers customers pay. Kept apart from the listeners above: this is
     * what a payer is told, it issues nothing, and one phone can forward for a
     * SIM whose number is listed here.
     */
    const numberSelect = el('number-provider');
    const numberColumns = [
      {label: 'Network', key: 'provider_label'},
      {label: 'Number', key: 'number'},
      {label: 'Name that comes up', key: 'account_name'},
      {
        label: '',
        cell: row => {
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'copy-button';
          remove.textContent = 'Remove';
          remove.onclick = async () => {
            if (!(await confirmAction('Remove this number?',
              'Customers will stop being shown ' + row.number + '. Payments already received are not affected.', 'Remove'))) return;
            try {
              await post('/v1/portal/numbers/remove', {number_id: row.id, merchant_id: scope.id});
              listNumbers();
            } catch (e) { show('number-form-error', e.message); }
          };
          const group = document.createElement('div');
          group.className = 'row-actions';
          group.append(remove);
          return group;
        },
      },
    ];

    async function listNumbers() {
      const query = scope.id ? '?merchant_id=' + encodeURIComponent(scope.id) : '';
      try {
        const data = await api('/v1/portal/numbers' + query);
        table(el('number-records'), numberColumns, data.numbers, {
          title: 'No numbers yet',
          body: 'Add the number your customers send money to, so the payment page can show it.',
        });
      } catch (e) {
        show('number-form-error', e.message);
      }
    }

    function loadNumberProviders() {
      numberSelect.replaceChildren();
      Array.from(select.options).forEach(option => {
        numberSelect.append(new Option(option.textContent, option.value));
      });
      numberSelect.disabled = select.disabled;
      el('number-form').querySelector('button[type=submit]').disabled = select.disabled;
      el('number-network-field').hidden = numberSelect.value !== 'other';
    }
    loadNumberProviders();
    numberSelect.onchange = () => { el('number-network-field').hidden = numberSelect.value !== 'other'; };

    el('number-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = el('number-form').querySelector('button[type=submit]');
      show('number-form-error', '');
      button.disabled = true;
      try {
        await post('/v1/portal/numbers', {
          provider: numberSelect.value,
          provider_name: el('number-network-name').value.trim(),
          number: el('number-value').value.trim(),
          account_name: el('number-name').value.trim(),
          merchant_id: scope.id,
        });
        el('number-form').reset();
        el('number-network-field').hidden = true;
        listNumbers();
      } catch (e) {
        show('number-form-error', e.message);
      } finally {
        button.disabled = false;
      }
    });
    listNumbers();

    const columns = [
      {label: 'Listener', key: 'label'},
      ...(scope.owner ? [{label: 'Merchant', key: 'merchant'}] : []),
      {label: 'Network', key: 'provider_label'},
      {
        label: 'State',
        cell: row => {
          if (row.health === 'revoked') return chip('Revoked', 'bad');
          if (row.health === 'online') return chip('Reporting', 'good');
          if (row.health === 'never') return chip('Never reported', 'warn');
          return chip('Quiet', 'warn');
        },
      },
      {label: 'Last reported', cell: row => when(row.last_seen)},
      {label: 'App', key: 'app_version'},
      {
        label: '',
        cell: row => {
          const group = document.createElement('div');
          group.className = 'row-actions';
          if (row.status === 'active') {
            const rotate = document.createElement('button');
            rotate.type = 'button';
            rotate.className = 'copy-button';
            rotate.textContent = 'New key';
            rotate.onclick = () => rotateKey(row);
            const revoke = document.createElement('button');
            revoke.type = 'button';
            revoke.className = 'copy-button danger';
            revoke.textContent = 'Revoke';
            revoke.onclick = () => revokeDevice(row);
            group.append(rotate, revoke);
          }
          // A phone can always be taken off the list, revoked or not.
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'copy-button danger';
          remove.textContent = 'Remove';
          remove.onclick = () => deleteDevice(row);
          group.append(remove);
          return group;
        },
      },
    ];

    async function list() {
      try {
        const data = await api('/v1/portal/operations');
        table(el('device-records'), columns, data.devices, {
          title: 'No listeners yet',
          body: 'Add one above, then paste its key into the app on that phone.',
        });
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }

    async function rotateKey(device) {
      const password = await confirmPassword('Issue a new key for ' + device.label,
        'The current key stops working immediately, so that phone will not report again until the new key is pasted into the app.',
        'Issue new key');
      if (!password) return;
      try {
        const data = await post('/v1/portal/devices/rotate', {device_id: device.id, password, merchant_id: device.merchant_id || scope.id});
        reveal('device-key-reveal', 'device-key-value', data.device_key);
        list();
      } catch (e) {
        problem(e.message);
      }
    }

    async function revokeDevice(device) {
      const password = await confirmPassword('Revoke ' + device.label,
        'This phone stops being able to report payments. Payments it already reported are kept.',
        'Revoke listener');
      if (!password) return;
      try {
        await post('/v1/portal/devices/revoke', {device_id: device.id, password, merchant_id: device.merchant_id || scope.id});
        list();
      } catch (e) {
        problem(e.message);
      }
    }

    // Revoking leaves a phone on the list, which is right while you are still
    // dealing with it. Once you are not, it is clutter.
    async function deleteDevice(device) {
      const password = await confirmPassword('Remove ' + device.label,
        'It comes off this list and stops being able to report payments. Payments and messages it already reported are kept.',
        'Remove listener');
      if (!password) return;
      try {
        await post('/v1/portal/devices/delete', {device_id: device.id, password, merchant_id: device.merchant_id || scope.id});
        list();
      } catch (e) {
        problem(e.message);
      }
    }

    el('device-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = el('device-form').querySelector('button[type=submit]');
      show('device-form-error', '');
      button.disabled = true;
      try {
        const data = await post('/v1/portal/devices', {
          provider: select.value,
          label: el('device-label').value.trim(),
          receiving_number: el('device-number').value.trim(),
          receiving_name: el('device-name').value.trim(),
          extra_senders: el('device-senders').value.trim(),
          provider_name: el('device-provider-name').value.trim(),
          merchant_id: scope.id,
        });
        el('device-form').reset();
        el('device-sender-field').hidden = true;
        reveal('device-key-reveal', 'device-key-value', data.device_key);
        list();
      } catch (e) {
        show('device-form-error', e.message);
      } finally {
        button.disabled = false;
      }
    });
    /**
     * When each phone was silent. A phone reports every five minutes, so anything
     * longer is a real gap, and the ones still going on are named as such.
     */
    async function uptime() {
      try {
        const data = await api('/v1/portal/uptime?days=' + el('uptime-days').value);
        const summary = el('uptime-summary');
        summary.replaceChildren();
        if (!data.devices.length) {
          const note = document.createElement('p');
          note.className = 'portal-empty quiet';
          note.textContent = 'No phone has reported in this period.';
          summary.append(note);
        } else {
          data.devices.forEach(row => {
            const line = document.createElement('p');
            line.className = 'uptime-line';
            const name = document.createElement('b');
            name.textContent = row.device || 'Listener';
            const detail = document.createElement('small');
            detail.textContent = ' reported ' + row.reports + ' times, from ' + when(row.first_seen) + ' to ' + when(row.last_seen);
            line.append(name, detail);
            summary.append(line);
          });
        }
        table(el('uptime-records'), [
          {label: 'Phone', key: 'device'},
          {label: 'Silent from', cell: row => when(row.from)},
          {label: 'Until', cell: row => row.ongoing ? 'still silent' : when(row.to)},
          {label: 'For', cell: row => row.minutes < 60 ? row.minutes + ' min'
            : Math.floor(row.minutes / 60) + 'h ' + (row.minutes % 60) + 'm'},
          {label: '', cell: row => row.ongoing ? chip('Now', 'bad') : chip('Recovered', 'warn')},
        ], data.outages, {
          title: 'No gaps in this period',
          body: 'Every phone kept reporting at least every ' + data.gap_minutes + ' minutes.',
        });
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }
    el('uptime-days').onchange = () => uptime();

    el('devices-refresh').onclick = () => { list(); uptime(); };
    scope.onchange = async () => {
      await loadProviders();
      loadNumberProviders();
      listNumbers();
      list();
      uptime();
    };
    list();
    uptime();
  };

  // --------------------------------------------------------------- developers
  pages['dashboard-developers'] = async () => {
    const account = await identify();
    const scope = await merchantScope(account, 'scope-picker',
      'No merchants yet. Add one under Merchants before issuing credentials.');
    if (account && account.merchant) el('webhook-url').value = account.merchant.webhook_url || '';
    const usable = scope.ready;
    [el('key-create'), el('secret-rotate'), el('webhook-form').querySelector('button[type=submit]')]
      .forEach(button => { button.disabled = !usable; });

    const keyColumns = [
      {label: 'Key', key: 'hint'},
      ...(scope.owner ? [{label: 'Merchant', key: 'merchant'}] : []),
      {label: 'State', cell: row => chip(titleCase(row.status), row.status === 'active' ? 'good' : 'bad')},
      {label: 'Created', cell: row => when(row.created_at)},
      {label: 'Last used', cell: row => when(row.last_used_at)},
      {
        label: '',
        cell: row => {
          if (row.status !== 'active') return '';
          const revoke = document.createElement('button');
          revoke.type = 'button';
          revoke.className = 'copy-button danger';
          revoke.textContent = 'Revoke';
          revoke.onclick = async () => {
            const password = await confirmPassword('Revoke key ' + row.hint,
              'Any billing system still using this key stops being able to reach the gateway at once.',
              'Revoke key');
            if (!password) return;
            try {
              await post('/v1/portal/keys/revoke', {hint: row.hint, password, merchant_id: row.merchant_id || scope.id});
              load();
            } catch (e) {
              problem(e.message);
            }
          };
          return revoke;
        },
      },
    ];
    const webhookColumns = [
      {label: 'Sent', cell: row => when(row.created_at)},
      {label: 'Event', key: 'event_id'},
      {label: 'Type', key: 'type'},
      {label: 'State', cell: row => chip(titleCase(row.status), row.status === 'delivered' ? 'good' : row.status === 'failed' ? 'bad' : 'warn')},
      {label: 'Attempts', key: 'attempts'},
      {label: 'Response', key: 'last_code'},
    ];

    async function load() {
      try {
        const data = await api('/v1/portal/operations');
        table(el('key-records'), keyColumns, data.keys, {
          title: 'No live keys yet',
          body: usable ? 'Create one above and store it in your billing system.'
            : (scope.owner ? 'Add a merchant first, then issue its credentials here.' : 'Complete live merchant registration to receive API credentials.'),
        });
        table(el('webhook-records'), webhookColumns, data.webhooks, {
          title: 'No deliveries yet',
          body: 'Deliveries appear here once a payment has been matched to a customer.',
        });
        problem('');
      } catch (e) {
        problem(e.message);
      }
    }

    el('key-create').onclick = async () => {
      const password = await confirmPassword('Create an API key',
        account && account.user.email_verified === false
          ? 'The key is shown once. Save it now; it can be used after you verify your email.'
          : 'The key is shown once, immediately after this. Store it in your billing system before leaving the page.',
        'Create key');
      if (!password) return;
      try {
        const data = await post('/v1/portal/keys', {password, merchant_id: scope.id});
        reveal('key-reveal', 'key-value', data.api_key);
        el('key-create-notice').textContent = (data.email_notice_sent
          ? 'A key-creation security alert was emailed to your account address. '
          : 'The key was created, but the email alert could not be sent. ')
          + (account && account.user.email_verified === false ? 'Verify your email before using this key for live payments.' : '');
        load();
      } catch (e) {
        problem(e.message);
      }
    };

    el('secret-rotate').onclick = async () => {
      const password = await confirmPassword('Rotate your signing secret',
        'New deliveries are signed with the new secret. Update your verification before the next payment arrives.',
        'Rotate secret');
      if (!password) return;
      try {
        const data = await post('/v1/portal/webhook/secret', {password, merchant_id: scope.id});
        reveal('secret-reveal', 'secret-value', data.webhook_secret);
      } catch (e) {
        problem(e.message);
      }
    };

    el('webhook-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = el('webhook-form').querySelector('button[type=submit]');
      show('webhook-form-error', '');
      show('webhook-form-ok', '');
      button.disabled = true;
      const was = button.textContent;
      button.textContent = 'Verifying…';
      try {
        const data = await post('/v1/portal/webhook', {webhook_url: el('webhook-url').value.trim(), merchant_id: scope.id});
        el('webhook-url').value = data.webhook_url;
        show('webhook-form-error', '');
        show('webhook-form-ok', data.verified === 'challenge'
          ? 'Saved. The address answered the verification request.'
          : 'Saved. The address is reachable and accepted the request.');
      } catch (e) {
        show('webhook-form-error', e.message);
      } finally {
        button.disabled = false;
        button.textContent = was;
      }
    });

    el('webhooks-refresh').onclick = () => load();
    load();
  };

  // ---------------------------------------------------------------- account
  pages['dashboard-account'] = async () => {
    const data = await identify();
    if (!data) {
      el('portal-loading').textContent = 'Your account could not be loaded.';
      return;
    }
    function details(target, pairs) {
      const list = el(target);
      pairs.forEach(([label, value]) => {
        if (value == null || value === '') return;
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = value;
        list.append(dt, dd);
      });
    }
    details('account-details', [
      ['Email', data.user.email],
      ['Phone', data.user.phone],
      ['Role', titleCase(data.user.role)],
      ['Member since', when(data.user.created_at)],
      ['Last signed in', when(data.user.last_login_at)],
    ]);
    if (data.merchant) {
      details('merchant-details', [
        ['Business', data.merchant.name],
        ['Merchant ID', data.merchant.id],
        ['Country', titleCase(data.merchant.country)],
        ['Currency', data.merchant.currency],
        ['Dialling code', '+' + data.merchant.dial_code],
        ['Webhook', data.merchant.webhook_url],
        ['Registered', when(data.merchant.created_at)],
      ]);
    } else {
      el('account-no-merchant').hidden = false;
      // The default wording is for an owner on the whole-gateway view.
      if (data.user.role !== 'owner') {
        el('no-merchant-title').textContent = 'No live merchant yet';
        el('no-merchant-text').textContent = 'This sign-in can use the sandbox. Create a live account to receive payments.';
      }
    }

    // An owner's own merchant account: open it, or create it, from right here.
    const mine = el('my-merchant');
    if (mine && data.user.role === 'owner') {
      mine.hidden = false;
      const open = el('my-merchant-open');
      if (data.own_merchant) {
        el('my-merchant-text').textContent = 'This sign-in also runs ' + data.own_merchant.name + '. Its keys, listener phones, webhook and payments are yours.';
        open.textContent = 'Open my merchant dashboard';
        open.onclick = () => viewAs(data.own_merchant.id).catch(e => problem(e.message));
      } else {
        open.onclick = () => openMyMerchant();
      }
    }

    // The zone every screen reads times in, including the listener phones.
    const zonePicker = el('account-timezone');
    if (zonePicker && data.merchant) {
      el('timezone-row').hidden = false;
      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = 'Use each reader’s own setting';
      zonePicker.append(blank);
      (data.timezones || []).forEach(zone => {
        const option = document.createElement('option');
        option.value = zone;
        option.textContent = zone.replace('_', ' ');
        zonePicker.append(option);
      });
      const own = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (own && !(data.timezones || []).includes(own)) {
        const option = document.createElement('option');
        option.value = own;
        option.textContent = own.replace('_', ' ') + ' (this device)';
        zonePicker.append(option);
      }
      zonePicker.value = data.merchant.timezone || '';
      zonePicker.onchange = async () => {
        try {
          await post('/v1/portal/timezone', {timezone: zonePicker.value});
          displayZone = zonePicker.value;
          show('timezone-ok', zonePicker.value
            ? 'Times are now shown in ' + zonePicker.value.replace('_', ' ') + ', here and on your listener phones.'
            : 'Times now follow whatever each reader’s own device is set to.');
        } catch (e) {
          problem(e.message);
        }
      };
    }

    el('password-form').addEventListener('submit', async event => {
      event.preventDefault();
      show('password-form-error', '');
      show('password-form-ok', '');
      const current = el('current-password').value;
      const next = el('new-password').value;
      if (next !== el('confirm-password').value) {
        show('password-form-error', 'The two new passwords do not match.');
        return;
      }
      if (next.length < 12) {
        show('password-form-error', 'Use a new password of at least 12 characters.');
        return;
      }
      const button = el('password-form').querySelector('button[type=submit]');
      button.disabled = true;
      try {
        await post('/v1/portal/password', {password: current, new_password: next});
        el('password-form').reset();
        show('password-form-ok', 'Your password is updated. Any other device signed in to this account has been signed out.');
      } catch (e) {
        show('password-form-error', e.message);
      } finally {
        button.disabled = false;
      }
    });

    el('portal-loading').hidden = true;
    el('portal-content').hidden = false;
  };

  if (pages[page]) pages[page]();
})();
