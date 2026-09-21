(function () {
  'use strict';
  var button = document.getElementById('resend-verification');
  var result = document.getElementById('resend-result');
  if (!button || !result) return;
  button.addEventListener('click', async function () {
    var email = document.getElementById('login-email').value.trim();
    if (!email || !document.getElementById('login-email').checkValidity()) {
      document.getElementById('login-email').reportValidity(); return;
    }
    button.disabled = true;
    result.textContent = 'Checking your request…';
    try {
      var response = await fetch('/v1/portal/resend-verification', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Portal-Request': '1' },
        body: JSON.stringify({ email: email })
      });
      var body = await response.json();
      result.textContent = response.ok ? body.message : body.error && body.error.message || 'Please try again later.';
    } catch (error) { result.textContent = 'Could not reach the service. Try again later.'; }
    finally { button.disabled = false; }
  });
})();
