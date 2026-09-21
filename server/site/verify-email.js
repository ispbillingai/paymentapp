(function () {
  'use strict';
  var result = document.getElementById('verification-result');
  if (!result) return;
  var match = /^#token=([a-f0-9]{64})$/.exec(location.hash);
  history.replaceState(null, '', '/verify-email');
  if (!match) { result.textContent = 'This link is invalid. Request a new verification email from the sign-in page.'; return; }
  fetch('/v1/portal/verify-email', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Portal-Request': '1' },
    body: JSON.stringify({ token: match[1] })
  }).then(function (response) { return response.json().then(function (data) { if (!response.ok) throw new Error(data.error && data.error.message || 'Verification failed.'); return data; }); })
    .then(function () { result.textContent = 'Your email is verified. You can now sign in and set up your payment gateway.'; })
    .catch(function (error) { result.textContent = error.message + ' You can request a new link from the sign-in page.'; });
})();
