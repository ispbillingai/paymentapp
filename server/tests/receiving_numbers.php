<?php
/**
 * Where customers pay is a list of its own, kept apart from the phones.
 *
 *   php tests/receiving_numbers.php
 *
 * A number, its network and the name that comes up are details shown to a payer.
 * They never read or match a payment, so adding one issues no key and needs no
 * phone. This checks that separation holds, that a merchant's list cannot leak
 * into another's, and that merchants who typed their number on a listener before
 * the split still have a payment page that tells customers where to send money.
 *
 * Runs on a throwaway MySQL database, created and dropped by mysql_bootstrap.
 */
require __DIR__ . '/mysql_bootstrap.php';
date_default_timezone_set('UTC');

class Db
{
    public static function pdo() { return TestDb::pdo(); }
    private static function statement($sql, $args) { $s = self::pdo()->prepare($sql); $s->execute($args); return $s; }
    public static function row($sql, $args = []) { return self::statement($sql, $args)->fetch() ?: null; }
    public static function rows($sql, $args = []) { return self::statement($sql, $args)->fetchAll(); }
    public static function run($sql, $args = []) { return self::statement($sql, $args)->rowCount(); }
    public static function lastId() { return (int) self::pdo()->lastInsertId(); }
}
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Gateway.php';

// The real schema, so a column this code writes must actually exist. Comments
// come out first: prose may contain a semicolon, and statements are split on it.
$schema = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if (stripos($statement, 'CREATE TABLE') === 0) {
        Db::pdo()->exec($statement);
    }
}

$checks = 0;
function check($ok, $message)
{
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$ghana = ['id' => 1, 'dial_code' => '233'];
$other = ['id' => 2, 'dial_code' => '233'];

// ---------------------------------------------------------------- adding
$added = Gateway::addNumber($ghana, ['provider' => 'mtn_gh', 'number' => '0551234567', 'account_name' => 'Kofi Mensah']);
check(isset($added['number']), 'A complete number is accepted.');
check($added['number']['provider_label'] === 'MTN MoMo', 'A built-in network keeps its own name.');
check(!isset($added['number']['key']) && !isset($added['number']['device_key']), 'Adding a number issues no key.');
check(Db::row("SELECT COUNT(*) c FROM devices")['c'] == 0, 'Adding a number creates no listener.');

check((Gateway::addNumber($ghana, ['provider' => 'mtn_gh', 'number' => '0551234567', 'account_name' => 'Kofi'])['error']['code'] ?? '') === 'number_exists',
    'The same number twice would give a customer two identical choices.');
check((Gateway::addNumber($ghana, ['provider' => 'mtn_gh', 'account_name' => 'Kofi'])['error']['code'] ?? '') === 'number_required',
    'A number is the point of the entry, so it is required.');
check((Gateway::addNumber($ghana, ['provider' => 'mtn_gh', 'number' => '0551112222'])['error']['code'] ?? '') === 'name_required',
    'The name a customer checks before confirming is required.');
check((Gateway::addNumber($ghana, ['provider' => 'mtn_gh', 'number' => '12', 'account_name' => 'Kofi'])['error']['code'] ?? '') === 'bad_number',
    'A number too short to dial is refused.');
check((Gateway::addNumber($ghana, ['provider' => 'nonesuch', 'number' => '0551112222', 'account_name' => 'Kofi'])['error']['code'] ?? '') === 'unknown_provider',
    'A network not offered in that country is refused.');
check((Gateway::addNumber($ghana, ['provider' => 'other', 'number' => '0551112222', 'account_name' => 'Kofi'])['error']['code'] ?? '') === 'network_required',
    'A network we do not know must be given a name, or a customer cannot choose it.');
check((Gateway::addNumber($ghana, ['provider' => ['x']])['error']['code'] ?? '') === 'bad_request',
    'Structured values are rejected cleanly.');

$named = Gateway::addNumber($ghana, ['provider' => 'other', 'provider_name' => 'Orange Money', 'number' => '0271112222', 'account_name' => 'Kofi Mensah']);
check(($named['number']['provider_label'] ?? '') === 'Orange Money', 'A network the merchant named is called what they called it.');
$builtIn = Gateway::addNumber($ghana, ['provider' => 'telecel_gh', 'provider_name' => 'Ignore me', 'number' => '0201112222', 'account_name' => 'Kofi Mensah']);
check(($builtIn['number']['provider_label'] ?? '') === 'Telecel Cash', 'A built-in network cannot be renamed into something else.');

// ---------------------------------------------------------------- listing
check(count(Gateway::numbers(1)) === 3, 'All three numbers are listed.');
check(Gateway::numbers(2) === [], 'Another merchant sees none of them.');

Gateway::addNumber($other, ['provider' => 'mtn_gh', 'number' => '0559998888', 'account_name' => 'Ama']);
$pay = Gateway::payTo(1);
check(count($pay) === 3, 'The payment page shows this merchant only.');
check($pay[0]['number'] === '0551234567' && $pay[0]['name'] === 'Kofi Mensah', 'Each entry carries its number and name.');
check(array_column($pay, 'provider') === ['MTN MoMo', 'Orange Money', 'Telecel Cash'], 'Each entry carries the name of its network.');

// ---------------------------------------------------------------- removing
check(Gateway::removeNumber($other, $named['number']['id']) === false, 'One merchant cannot remove another merchant\'s number.');
check(count(Gateway::numbers(1)) === 3, 'And nothing was removed by the attempt.');
check(Gateway::removeNumber($ghana, $named['number']['id']) === true, 'A merchant can remove their own.');
check(count(Gateway::payTo(1)) === 2, 'A removed number is no longer shown to customers.');
check(Gateway::removeNumber($ghana, 'num_nonesuch') === false, 'Removing something that is not there says so.');

// ------------------------------------------- listeners from before the split
// A phone paired before this list existed carries a number of its own. It still
// tells customers where to pay, and never twice.
Db::run(
    "INSERT INTO devices (public_id, merchant_id, label, provider, provider_name, receiving_number, receiving_key, receiving_name, extra_senders, key_hash, status, created_at)
     VALUES ('dev_old', 1, 'Old phone', 'mtn_gh', '', '0557778888', '557778888', 'Kofi Mensah', '', 'x', 'active', '2026-09-01 00:00:00')",
    []
);
$pay = Gateway::payTo(1);
check(count($pay) === 3 && $pay[2]['number'] === '0557778888', 'A number typed on a listener is still shown.');
Db::run("UPDATE devices SET receiving_number = '0551234567' WHERE public_id = 'dev_old'", []);
check(count(Gateway::payTo(1)) === 2, 'A number entered both ways is shown once, not twice.');
Db::run("UPDATE devices SET status = 'revoked' WHERE public_id = 'dev_old'", []);
check(count(Gateway::payTo(1)) === 2, 'A revoked listener stops speaking for its number.');

// ------------------------------------------------------- the listener itself
$device = Gateway::createDevice($ghana, ['provider' => 'mtn_gh', 'label' => 'Front desk']);
check(isset($device['device_key']) && strlen($device['device_key']) === 40, 'A listener still gets its own 40 character key.');
check(($device['device']['provider_label'] ?? '') === 'MTN MoMo', 'A listener still names its network.');
$custom = Gateway::createDevice($ghana, ['provider' => 'other', 'extra_senders' => 'ORANGEMONEY', 'provider_name' => 'Orange Money', 'label' => 'Shop']);
check(($custom['device']['provider_label'] ?? '') === 'Orange Money', 'A listener on a named network shows that name.');
$unnamed = Gateway::createDevice($ghana, ['provider' => 'other', 'extra_senders' => 'ZEEPAY, OTHER', 'label' => 'Spare']);
check(($unnamed['device']['provider_label'] ?? '') === 'ZEEPAY', 'Unnamed, the sender name it listens for stands in.');
check(count(Gateway::payTo(1)) === 2, 'Adding listeners does not change where customers pay.');

// ------------------------------------------------- taking a phone off the list
// A merchant row of its own, because authenticating a key joins to one.
Db::run("INSERT INTO merchants (id, public_id, name, country, dial_code, currency, webhook_url, webhook_secret, status, created_at)
         VALUES (1, 'mer_test', 'Test', 'ghana', '233', 'GHS', NULL, 's', 'active', '2026-09-01 00:00:00')", []);
$spare = Gateway::createDevice($ghana, ['provider' => 'mtn_gh', 'label' => 'Old phone']);
$spareId = $spare['device']['id'];
check(Gateway::deviceByKey($spare['device_key']) !== null, 'A listener key works while the listener is listed.');
check(Gateway::deleteDevice($other, $spareId) === false, 'One merchant cannot remove another merchant\'s phone.');
check(Gateway::deleteDevice($ghana, $spareId) === true, 'A merchant can remove their own.');
check(Gateway::deviceByKey($spare['device_key']) === null, 'A removed phone can no longer report anything.');
check(Gateway::deleteDevice($ghana, $spareId) === false, 'Removing it again says there is nothing there.');
check(Gateway::revokeDevice($ghana, $spareId) === false, 'And it cannot be revoked after the fact.');
check(Gateway::rotateDeviceKey($ghana, $spareId) === null, 'Nor brought back by issuing it a key.');
check(Db::row("SELECT status FROM devices WHERE public_id = ?", [$spareId])['status'] === 'deleted',
    'The row stays, marked deleted, so payments it reported still name it.');
$listed = array_column(Db::rows("SELECT public_id FROM devices WHERE merchant_id = 1 AND status <> 'deleted'"), 'public_id');
check(!in_array($spareId, $listed, true) && count($listed) > 0,
    'And it is gone from the list the dashboard shows, which still has the others.');

echo "PASS: $checks checks. Numbers are separate from listeners, and issue nothing.\n";
