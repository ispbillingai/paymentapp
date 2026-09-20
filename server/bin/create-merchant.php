<?php
/**
 * Creates a merchant by hand and prints its keys once.
 *   php bin/create-merchant.php "Kofi Net" ghana 233 GHS https://kofinet.example.com/directpay/webhook.php
 * Providers that run the billing dashboard join on their own from its Direct Number page.
 */
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Parser.php';
require dirname(__DIR__) . '/src/Gateway.php';
date_default_timezone_set('UTC');

if ($argc < 6) {
    fwrite(STDERR, "usage: create-merchant.php <name> <country> <dial_code> <currency> <webhook_url>\n");
    exit(1);
}
$made = Gateway::createMerchant($argv[1], $argv[2], $argv[3], $argv[4], $argv[5]);
echo "merchant id     " . $made['merchant']['public_id'] . PHP_EOL;
echo "api key         " . $made['api_key'] . PHP_EOL;
echo "webhook secret  " . $made['webhook_secret'] . PHP_EOL;
echo "These are shown once. Store them now." . PHP_EOL;
