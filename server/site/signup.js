(function () {
  'use strict';

  var countries = [
    ['dz','Algeria','213','DZD'],['ao','Angola','244','AOA'],['bj','Benin','229','XOF'],['bw','Botswana','267','BWP'],['bf','Burkina Faso','226','XOF'],['bi','Burundi','257','BIF'],['cv','Cabo Verde','238','CVE'],['cm','Cameroon','237','XAF'],['cf','Central African Republic','236','XAF'],['td','Chad','235','XAF'],['km','Comoros','269','KMF'],['cd','Congo, Democratic Republic','243','CDF'],['cg','Congo, Republic','242','XAF'],['ci','Côte d’Ivoire','225','XOF'],['dj','Djibouti','253','DJF'],['eg','Egypt','20','EGP'],['gq','Equatorial Guinea','240','XAF'],['er','Eritrea','291','ERN'],['sz','Eswatini','268','SZL'],['et','Ethiopia','251','ETB'],['ga','Gabon','241','XAF'],['gm','Gambia','220','GMD'],['gh','Ghana','233','GHS'],['gn','Guinea','224','GNF'],['gw','Guinea-Bissau','245','XOF'],['ls','Lesotho','266','LSL'],['lr','Liberia','231','LRD'],['ly','Libya','218','LYD'],['mg','Madagascar','261','MGA'],['mw','Malawi','265','MWK'],['ml','Mali','223','XOF'],['mr','Mauritania','222','MRU'],['mu','Mauritius','230','MUR'],['ma','Morocco','212','MAD'],['mz','Mozambique','258','MZN'],['na','Namibia','264','NAD'],['ne','Niger','227','XOF'],['ng','Nigeria','234','NGN'],['rw','Rwanda','250','RWF'],['st','São Tomé and Príncipe','239','STN'],['sn','Senegal','221','XOF'],['sc','Seychelles','248','SCR'],['sl','Sierra Leone','232','SLE'],['so','Somalia','252','SOS'],['za','South Africa','27','ZAR'],['ss','South Sudan','211','SSP'],['sd','Sudan','249','SDG'],['tz','Tanzania','255','TZS'],['tg','Togo','228','XOF'],['tn','Tunisia','216','TND'],['ug','Uganda','256','UGX'],['zm','Zambia','260','ZMW'],['zw','Zimbabwe','263','USD']
  ];
  var form = document.getElementById('merchant-signup');
  if (!form) return;
  var country = document.getElementById('merchant-country');
  var dial = document.getElementById('merchant-dial');
  var currency = document.getElementById('merchant-currency');
  var nonce = document.getElementById('signup-nonce');
  var errorBox = document.getElementById('signup-error');
  var submit = document.getElementById('register-button');
  var credentials = null;

  countries.forEach(function (item) {
    var option = document.createElement('option');
    option.value = item[0]; option.textContent = item[1]; option.dataset.dial = item[2]; option.dataset.currency = item[3];
    country.appendChild(option);
  });
  country.addEventListener('change', function () {
    var selected = country.options[country.selectedIndex];
    if (!selected || !selected.value) return;
    dial.value = selected.dataset.dial || '';
    currency.value = selected.dataset.currency || '';
  });
  currency.addEventListener('input', function () { currency.value = currency.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3); });
  dial.addEventListener('input', function () { dial.value = dial.value.replace(/\D/g, '').slice(0, 8); });

  function randomNonce() {
    var bytes = new Uint8Array(24); window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }
  nonce.textContent = randomNonce();
  function showStep(number) {
    document.querySelectorAll('[data-step]').forEach(function (step) { step.hidden = Number(step.dataset.step) !== number; });
    document.querySelectorAll('[data-progress]').forEach(function (item) { item.classList.toggle('active', Number(item.dataset.progress) <= number); item.removeAttribute('aria-current'); if (Number(item.dataset.progress) === number) item.setAttribute('aria-current','step'); });
    document.querySelector('.signup-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function validateStepOne() {
    document.getElementById('merchant-password-confirm').setCustomValidity('');
    var controls = document.querySelectorAll('[data-step="1"] input,[data-step="1"] select');
    for (var i = 0; i < controls.length; i++) if (!controls[i].reportValidity()) return false;
    var password = document.getElementById('merchant-password');
    var confirmation = document.getElementById('merchant-password-confirm');
    confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords must match.');
    return confirmation.reportValidity();
  }
  document.querySelector('[data-next]').addEventListener('click', function () { if (validateStepOne()) showStep(2); });
  document.querySelector('[data-back]').addEventListener('click', function () { showStep(1); });

  var messages = {
    challenge_failed: 'We could not verify that webhook. Confirm it returns HTTP 200 with the exact challenge value, then try again.',
    invalid_webhook_url: 'Use a public HTTPS webhook URL. Local and private network addresses cannot be registered.',
    country_not_supported: 'This country is not available for direct-payment registration.',
    merchant_exists: 'This webhook is already registered. Authenticate with its current API key to rotate credentials.',
    too_many_attempts: 'Too many registration attempts were made from this connection. Wait before trying again.'
  };
  form.addEventListener('submit', async function (event) {
    event.preventDefault(); errorBox.hidden = true;
    if (!form.reportValidity()) return;
    submit.disabled = true; submit.textContent = 'Verifying webhook…';
    var payload = { name: document.getElementById('merchant-name').value.trim(), email: document.getElementById('merchant-email').value.trim(), phone: document.getElementById('merchant-phone').value.trim(), password: document.getElementById('merchant-password').value, channel: 'direct', country: country.value, dial_code: dial.value, currency: currency.value.toUpperCase(), webhook_url: document.getElementById('webhook-url').value.trim(), nonce: nonce.textContent };
    try {
      var response = await fetch('/v1/merchants/register', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload), credentials: 'same-origin' });
      var body = await response.json().catch(function () { return {}; });
      if (!response.ok) throw { code: body.error?.code, message: body.error?.message };
      credentials = { merchant_id: body.merchant_id, api_key: body.api_key, webhook_secret: body.webhook_secret, webhook_url: payload.webhook_url, created_at: new Date().toISOString() };
      document.getElementById('result-merchant').textContent = credentials.merchant_id;
      document.getElementById('result-api-key').textContent = credentials.api_key;
      document.getElementById('result-webhook-secret').textContent = credentials.webhook_secret;
      form.reset(); form.hidden = true; document.getElementById('credential-result').hidden = false; showStep(3);
    } catch (problem) {
      errorBox.textContent = messages[problem.code] || problem.message || 'Account creation could not be completed. Check your webhook and try again.';
      errorBox.hidden = false; errorBox.focus();
    } finally { submit.disabled = false; submit.innerHTML = 'Verify and create account <span aria-hidden="true">→</span>'; }
  });
  document.getElementById('download-credentials').addEventListener('click', function () {
    if (!credentials) return;
    var blob = new Blob([JSON.stringify(credentials, null, 2) + '\n'], { type: 'application/json' });
    var link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'isp-billing-pay-' + credentials.merchant_id + '.json'; link.click();
    setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  });
})();
