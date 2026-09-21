<?php
// Copy to config.php (which is never committed) and fill in.
return [
    'db' => ['host' => 'localhost', 'name' => 'paymentgateway', 'user' => 'paymentgateway', 'pass' => ''],

    // Dialling codes Direct Number refuses, e.g. ['254']. Empty means every country
    // may register; a billing platform still decides for itself where it offers it.
    'blocked_dial_codes' => [],

    // Headers to trust for the caller's address, only if this sits behind a proxy you control.
    // Example: ['HTTP_CF_CONNECTING_IP']
    'trusted_ip_headers' => [],

    // Local testing only. Allows http and private addresses as webhook targets.
    'allow_insecure_webhooks' => false,
];
