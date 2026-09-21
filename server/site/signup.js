/**
 * Creating an account. One form, and the person arrives signed in on their
 * dashboard, where keys, the listener phone and the webhook are set up. Nothing
 * secret is shown on this public page, because nothing secret is issued here.
 */
(function () {
  'use strict';

  var form = document.getElementById('merchant-signup');
  if (!form) return;
  var countries = window.ISPPayCountries || [];
  var country = document.getElementById('merchant-country');
  var otherField = document.getElementById('merchant-country-other-field');
  var other = document.getElementById('merchant-country-other');
  var dial = document.getElementById('merchant-dial');
  var currency = document.getElementById('merchant-currency');
  var errorBox = document.getElementById('signup-error');
  var submit = document.getElementById('register-button');

  countries.forEach(function (item) {
    var option = document.createElement('option');
    option.value = item[0]; option.textContent = item[1]; option.dataset.dial = item[2]; option.dataset.currency = item[3];
    country.appendChild(option);
  });
  // The gateway works wherever a payment message arrives, so a country that is
  // not listed is typed in, with its own calling code and currency.
  var elsewhere = document.createElement('option');
  elsewhere.value = 'other'; elsewhere.textContent = 'Another country';
  country.appendChild(elsewhere);

  country.addEventListener('change', function () {
    var selected = country.options[country.selectedIndex];
    var typed = country.value === 'other';
    otherField.hidden = !typed;
    other.required = typed;
    if (typed) { dial.value = ''; currency.value = ''; other.focus(); return; }
    if (!selected || !selected.value) return;
    dial.value = selected.dataset.dial || '';
    currency.value = selected.dataset.currency || '';
  });
  currency.addEventListener('input', function () { currency.value = currency.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); });
  dial.addEventListener('input', function () { dial.value = dial.value.replace(/\D/g, '').slice(0, 8); });

  /** The country as the gateway stores it: a lower-case name, as billing platforms use. */
  function countryName() {
    if (country.value === 'other') return other.value.trim().toLowerCase();
    var selected = country.options[country.selectedIndex];
    return selected ? selected.textContent.trim().toLowerCase() : '';
  }

  var messages = {
    country_not_supported: 'Direct payments are not available in this country.',
    email_exists: 'That email already has an account. Sign in instead.',
    too_many_attempts: 'Too many attempts were made from this connection. Wait a while before trying again.'
  };

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    errorBox.hidden = true;
    var confirmation = document.getElementById('merchant-password-confirm');
    confirmation.setCustomValidity('');
    if (!form.reportValidity()) return;
    confirmation.setCustomValidity(document.getElementById('merchant-password').value === confirmation.value ? '' : 'Passwords must match.');
    if (!confirmation.reportValidity()) return;

    submit.disabled = true;
    submit.textContent = 'Creating your account…';
    var payload = {
      name: document.getElementById('merchant-name').value.trim(),
      email: document.getElementById('merchant-email').value.trim(),
      phone: document.getElementById('merchant-phone').value.trim(),
      password: document.getElementById('merchant-password').value,
      country: countryName(), dial_code: dial.value, currency: currency.value.toUpperCase()
    };
    try {
      var response = await fetch('/v1/portal/signup', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Portal-Request': '1' },
        body: JSON.stringify(payload)
      });
      var body = await response.json().catch(function () { return {}; });
      if (!response.ok) throw { code: body.error && body.error.code, message: body.error && body.error.message };
      if (body.verification_required) {
        form.innerHTML = '<div class="signup-step"><p class="eyebrow">ONE MORE STEP</p><h2>Check your email.</h2><p>We sent a verification link to your work address. Open it to activate your account, then sign in. The link expires in 24 hours.</p><div class="form-actions"><a class="button button-dark" href="/login">Go to sign in →</a></div></div>';
        return;
      }
      location.assign(body.next || '/dashboard');
    } catch (problem) {
      errorBox.textContent = messages[problem.code] || problem.message || 'Your account could not be created. Check your details and try again.';
      errorBox.hidden = false; errorBox.focus();
      submit.disabled = false;
      submit.innerHTML = 'Create account <span aria-hidden="true">→</span>';
    }
  });
})();
