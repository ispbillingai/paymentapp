<?php
// Copy to config.php (which is never committed) and fill in.
return [
    'db' => ['host' => 'localhost', 'name' => 'paymentgateway', 'user' => 'paymentgateway', 'pass' => ''],

    // Countries where Direct Number is not offered. Kenya has M-Pesa's own API.
    'blocked_dial_codes' => ['254'],

    // Headers to trust for the caller's address, only if this sits behind a proxy you control.
    // Example: ['HTTP_CF_CONNECTING_IP']
    'trusted_ip_headers' => [],

    // Local testing only. Allows http and private addresses as webhook targets.
    'allow_insecure_webhooks' => false,
];
