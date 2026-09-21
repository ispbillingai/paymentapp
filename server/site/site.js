'use strict';
(() => {
  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.main-navigation');

  // Someone already signed in is offered their dashboard, not another sign-in.
  // The marker is only a hint; the dashboard itself still checks the real session.
  if (/(?:^|;\s*)isp_pay_in=1(?:;|$)/.test(document.cookie)) {
    const signIn = document.querySelector('.main-navigation .nav-support');
    if (signIn) { signIn.textContent = 'Dashboard'; signIn.href = '/dashboard'; }
    const create = document.querySelector('.main-navigation a[data-nav="signup"]');
    if (create) { create.textContent = 'Open dashboard →'; create.href = '/dashboard'; }
  }
  function closeMenu() {
    if (!toggle || !nav) return;
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open menu');
    nav.classList.remove('open');
  }
  toggle?.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    nav.classList.toggle('open', open);
  });
  nav?.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && toggle?.getAttribute('aria-expanded') === 'true') {
      closeMenu(); toggle.focus();
    }
  });
  document.addEventListener('click', event => { if (!event.target.closest('.site-header')) closeMenu(); });
  const activePage = document.body.dataset.page;
  document.querySelectorAll('[data-nav]').forEach(link => {
    if (link.dataset.nav === activePage) link.setAttribute('aria-current', 'page');
  });
  let toastTimer;
  function toast(message) {
    const target = document.getElementById('site-toast');
    if (!target) return;
    target.textContent = message;
    target.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => target.classList.remove('visible'), 3000);
  }
  document.querySelectorAll('[data-copy-target]').forEach(button => {
    const original = button.textContent;
    let resetTimer;
    button.addEventListener('click', async () => {
      const source = document.getElementById(button.dataset.copyTarget);
      if (!source) return;
      try {
        await navigator.clipboard.writeText(source.textContent.trim());
        button.textContent = 'Copied ✓';
        toast('Copied to clipboard');
        clearTimeout(resetTimer);
        resetTimer = setTimeout(() => { button.textContent = original; }, 1800);
      } catch (_) {
        const range = document.createRange();
        range.selectNodeContents(source);
        const selection = window.getSelection();
        selection.removeAllRanges(); selection.addRange(range);
        toast('Text selected. Use your device’s copy command.');
      }
    });
  });
  const explanations = {
    'DEMO-0194': 'Payer number and amount match the waiting purchase. A payment.matched event is ready for the billing system.',
    'DEMO-0195': 'A separate transaction ID records this as a separate payment. The matching purchase can be fulfilled once by your billing system.',
    'DEMO-0196': 'No matching purchase was found for this payer number. The receipt stays unmatched for a verified claim or an authorised review.'
  };
  function selectPayment(row) {
    document.querySelectorAll('.demo-payment').forEach(other => {
      other.classList.toggle('selected', other === row);
      other.setAttribute('aria-pressed', String(other === row));
    });
    document.getElementById('demo-reference').textContent = row.dataset.paymentId;
    document.getElementById('demo-explanation').textContent = explanations[row.dataset.paymentId];
  }
  document.querySelectorAll('.demo-payment').forEach(row => row.addEventListener('click', () => selectPayment(row)));
  document.querySelectorAll('[data-payment-filter]').forEach(filter => {
    filter.addEventListener('click', () => {
      document.querySelectorAll('[data-payment-filter]').forEach(other => {
        other.classList.toggle('active', other === filter);
        other.setAttribute('aria-pressed', String(other === filter));
      });
      const visible = [];
      document.querySelectorAll('.demo-payment').forEach(row => {
        row.hidden = filter.dataset.paymentFilter !== 'all' && row.dataset.paymentStatus !== filter.dataset.paymentFilter;
        if (!row.hidden) visible.push(row);
      });
      if (!visible.some(row => row.classList.contains('selected')) && visible[0]) selectPayment(visible[0]);
    });
  });
  if ('IntersectionObserver' in window) {
    const sections = document.querySelectorAll('.doc-section[id]');
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        document.querySelectorAll('.docs-sidebar a').forEach(link => {
          const active = link.hash === '#' + entry.target.id;
          link.classList.toggle('active', active);
          if (active) link.setAttribute('aria-current', 'location');
          else link.removeAttribute('aria-current');
        });
      });
    }, {rootMargin: '-110px 0px -65% 0px'});
    sections.forEach(section => observer.observe(section));
  }
})();
