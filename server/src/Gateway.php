<?php

require_once __DIR__ . '/WebhookTarget.php';

/**
 * The payment gateway.
 *
 * A merchant (an internet provider's billing system) asks for money with a
 * payment intent: this amount, from this number, for this reference of mine.
 * A payment arrives from a source. Today the only source is Direct Number: a
 * listener phone forwarding the mobile money confirmation it received. The
 * gateway matches payments to intents and tells the merchant with a signed
 * webhook. What the merchant does with a paid intent is the merchant's
 * business; the gateway never knows about packages, routers or customers.
 *
 * Matching rules, agreed and not to be loosened:
 *   the payer NUMBER must equal the number on a waiting intent
 *   the AMOUNT must equal the intent's amount, and is never enough on its own
 *   the NAME confirms, and decides only when a number is already known
 *   against a different reference (the "typed my neighbour's number" case)
 */
class Gateway
{
    const INTENT_HOURS = 24;
    const CLAIM_DAYS = 7;

    // ---------------------------------------------------------------
    // Identifiers and keys
    // ---------------------------------------------------------------

    public static function newId($prefix)
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private static function now()
    {
        return date('Y-m-d H:i:s');
    }

    /** Keys are shown once and stored only as a hash. */
    public static function issueApiKey($merchantId)
    {
        $key = 'sk_live_' . bin2hex(random_bytes(24));
        Db::run(
            "INSERT INTO api_keys (merchant_id, key_hash, hint, status, created_at) VALUES (?,?,?, 'active', ?)",
            [(int) $merchantId, hash('sha256', $key), substr($key, 0, 12), self::now()]
        );
        return $key;
    }

    public static function merchantByApiKey($key)
    {
        if (!preg_match('/^sk_live_[a-f0-9]{48}$/', (string) $key)) {
            return null;
        }
        $m = Db::row(
            "SELECT m.*, k.id AS key_id FROM api_keys k JOIN merchants m ON m.id = k.merchant_id
              WHERE k.key_hash = ? AND k.status = 'active' AND m.status = 'active'",
            [hash('sha256', $key)]
        );
        if ($m) {
            Db::run("UPDATE api_keys SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)", [self::now(), (int) $m['key_id'], date('Y-m-d H:i:s', time() - 300)]);
        }
        return $m;
    }

    /**
     * $issueKey is false when the merchant is created from the workspace: they make
     * their first key there, where it is shown to them, rather than having one
     * issued here that nobody ever sees.
     */
    public static function createMerchant($name, $country, $dialCode, $currency, $webhookUrl, $issueKey = true)
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        Db::run(
            "INSERT INTO merchants (public_id, name, country, dial_code, currency, webhook_url, webhook_secret, status, created_at)
             VALUES (?,?,?,?,?,?,?, 'active', ?)",
            [self::newId('mer'), substr($name, 0, 120), strtolower(substr($country, 0, 40)), preg_replace('/\D+/', '', $dialCode), strtoupper(substr($currency, 0, 5)), (string) $webhookUrl === '' ? null : $webhookUrl, $secret, self::now()]
        );
        $id = Db::lastId();
        return ['merchant' => Db::row("SELECT * FROM merchants WHERE id = ?", [$id]), 'api_key' => $issueKey ? self::issueApiKey($id) : null, 'webhook_secret' => $secret];
    }

    // ---------------------------------------------------------------
    // Devices (listener phones)
    // ---------------------------------------------------------------

    public static function deviceByKey($key)
    {
        if (!preg_match('/^[a-f0-9]{40}$/', (string) $key)) {
            return null;
        }
        return Db::row(
            "SELECT d.*, m.dial_code, m.currency AS merchant_currency, m.status AS merchant_status FROM devices d JOIN merchants m ON m.id = d.merchant_id
              WHERE d.key_hash = ? AND d.status = 'active' AND m.status = 'active'",
            [hash('sha256', $key)]
        );
    }

    public static function createDevice(array $m, array $in)
    {
        foreach (['provider', 'provider_name', 'receiving_number', 'receiving_name', 'label', 'extra_senders'] as $field) {
            if (isset($in[$field]) && !is_string($in[$field])) {
                return ['error' => ['code' => 'bad_request', 'message' => 'Device details must be text values.']];
            }
        }
        $provider = (string) ($in['provider'] ?? '');
        $number = preg_replace('/[^\d+]/', '', (string) ($in['receiving_number'] ?? ''));
        $name = trim((string) ($in['receiving_name'] ?? ''));
        // A merchant can configure the exact sender name for another network.
        // Its receipt format still has to pass the parser's existing checks.
        $extraSenders = trim((string) ($in['extra_senders'] ?? ''));
        // A network we do not know still has to be called something, because a
        // customer choosing where to pay is choosing between network names. Left
        // unsaid, the sender name the messages arrive from is the closest thing
        // to it, and is what the merchant has already typed.
        $providerName = '';
        if ($provider === 'other') {
            if ($extraSenders === '') {
                return ['error' => ['code' => 'sender_required', 'message' => 'Type the sender name your payment messages arrive from, exactly as your phone shows it.']];
            }
            $providerName = trim((string) ($in['provider_name'] ?? ''));
            if ($providerName === '') {
                $providerName = trim((string) strtok($extraSenders, ','));
            }
        } elseif (!array_key_exists($provider, Parser::defaultSenders($m['dial_code']))) {
            return ['error' => ['code' => 'unknown_provider', 'message' => 'Choose your mobile money network.']];
        }
        // Both may be left blank. They are not used to read or match a payment: the
        // message names the sender, not the receiver, and ingest keys on the device
        // itself when there is no number. They are only how a customer is told where
        // to send money, which a phone pairing itself has no reason to know.
        $rkey = $number === '' ? '' : Parser::msisdnKey($number, Parser::msisdnDigitsFor($m['dial_code']));
        if ($number !== '' && $rkey === '') {
            return ['error' => ['code' => 'bad_number', 'message' => 'That does not look like a full number. Include the country code, or leave it blank.']];
        }
        $key = bin2hex(random_bytes(20));
        Db::run(
            "INSERT INTO devices (public_id, merchant_id, label, provider, provider_name, receiving_number, receiving_key, receiving_name, extra_senders, key_hash, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'active', ?)",
            [self::newId('dev'), (int) $m['id'], substr(trim((string) ($in['label'] ?? '')) ?: ($name !== '' ? $name : 'Listener'), 0, 80), $provider, substr($providerName, 0, 40), substr($number, 0, 20), $rkey, substr($name, 0, 100), substr(trim((string) ($in['extra_senders'] ?? '')), 0, 255), hash('sha256', $key), self::now()]
        );
        $d = Db::row("SELECT * FROM devices WHERE id = ?", [Db::lastId()]);
        return ['device' => self::deviceOut($d), 'device_key' => $key];
    }

    public static function rotateDeviceKey(array $m, $publicId)
    {
        $key = bin2hex(random_bytes(20));
        // A deleted phone is not brought back to life by issuing it a key.
        $n = Db::run("UPDATE devices SET key_hash = ?, status = 'active' WHERE merchant_id = ? AND public_id = ? AND status <> 'deleted'", [hash('sha256', $key), (int) $m['id'], $publicId]);
        return $n ? ['device_key' => $key] : null;
    }

    public static function revokeDevice(array $m, $publicId)
    {
        $d = Db::row("SELECT id FROM devices WHERE merchant_id = ? AND public_id = ? AND status <> 'deleted'", [(int) $m['id'], $publicId]);
        if (!$d) {
            return false;
        }
        Db::run("UPDATE devices SET status = 'revoked' WHERE id = ? AND status <> 'revoked'", [(int) $d['id']]);
        return true;
    }

    /**
     * Takes a phone off the list for good.
     *
     * Revoking stops a phone working and leaves it on the list, which is right
     * while you are still dealing with it and clutter once you are done. This
     * removes it from every list instead. The row stays, marked deleted, because
     * payments and messages already recorded name the phone that reported them,
     * and a payment whose listener had vanished would be a payment that came
     * from nowhere. Its key stops working either way: only an active device
     * authenticates.
     */
    public static function deleteDevice(array $m, $publicId)
    {
        $d = Db::row("SELECT id FROM devices WHERE merchant_id = ? AND public_id = ? AND status <> 'deleted'", [(int) $m['id'], $publicId]);
        if (!$d) {
            return false;
        }
        Db::run("UPDATE devices SET status = 'deleted' WHERE id = ?", [(int) $d['id']]);
        return true;
    }

    /**
     * What a network is called.
     *
     * A built-in network has a name we already know. One the merchant named
     * themselves is called what they called it, which is what their customers
     * will be choosing between on the payment page.
     */
    public static function providerName(array $row)
    {
        $named = trim((string) (isset($row['provider_name']) ? $row['provider_name'] : ''));
        return $named !== '' ? $named : Parser::providerLabel($row['provider']);
    }

    /** @deprecated kept so older callers keep working; use providerName(). */
    public static function deviceProvider(array $d)
    {
        return self::providerName($d);
    }

    // ---------------------------------------------------------------
    // Where customers pay
    //
    // A number, its network, and the name that comes up when a customer types
    // it. None of this reads a payment: the message names the sender, never the
    // receiver, so a number here is only ever an instruction to a customer. That
    // is why adding one issues no key and needs no phone.
    // ---------------------------------------------------------------

    public static function addNumber(array $m, array $in)
    {
        foreach (['provider', 'provider_name', 'number', 'account_name'] as $field) {
            if (isset($in[$field]) && !is_string($in[$field])) {
                return ['error' => ['code' => 'bad_request', 'message' => 'Number details must be text values.']];
            }
        }
        $provider = trim((string) ($in['provider'] ?? ''));
        $providerName = trim((string) ($in['provider_name'] ?? ''));
        $number = preg_replace('/[^\d+]/', '', (string) ($in['number'] ?? ''));
        $accountName = trim((string) ($in['account_name'] ?? ''));

        if ($provider === 'other') {
            if ($providerName === '') {
                return ['error' => ['code' => 'network_required', 'message' => 'Type what to call this network, as your customers know it.']];
            }
        } elseif (!array_key_exists($provider, Parser::defaultSenders($m['dial_code']))) {
            return ['error' => ['code' => 'unknown_provider', 'message' => 'Choose the mobile money network.']];
        } else {
            $providerName = '';
        }
        if ($number === '') {
            return ['error' => ['code' => 'number_required', 'message' => 'Type the number your customers send money to.']];
        }
        if (Parser::msisdnKey($number, Parser::msisdnDigitsFor($m['dial_code'])) === '') {
            return ['error' => ['code' => 'bad_number', 'message' => 'That does not look like a full number. Include the country code.']];
        }
        if ($accountName === '') {
            return ['error' => ['code' => 'name_required', 'message' => 'Type the name that comes up when someone sends to this number.']];
        }
        // The same number twice would give a customer two identical choices.
        $there = Db::row(
            "SELECT id FROM receiving_numbers WHERE merchant_id = ? AND number = ? AND status = 'active'",
            [(int) $m['id'], substr($number, 0, 20)]
        );
        if ($there) {
            return ['error' => ['code' => 'number_exists', 'message' => 'That number is already on your list.']];
        }
        Db::run(
            "INSERT INTO receiving_numbers (public_id, merchant_id, provider, provider_name, number, account_name, status, created_at)
             VALUES (?,?,?,?,?,?, 'active', ?)",
            [self::newId('num'), (int) $m['id'], substr($provider, 0, 30), substr($providerName, 0, 40),
             substr($number, 0, 20), substr($accountName, 0, 100), self::now()]
        );
        return ['number' => self::numberOut(Db::row("SELECT * FROM receiving_numbers WHERE id = ?", [Db::lastId()]))];
    }

    public static function numbers($merchantId)
    {
        $out = [];
        foreach (Db::rows("SELECT * FROM receiving_numbers WHERE merchant_id = ? AND status = 'active' ORDER BY id", [(int) $merchantId]) as $n) {
            $out[] = self::numberOut($n);
        }
        return $out;
    }

    public static function removeNumber(array $m, $publicId)
    {
        $n = Db::row(
            "SELECT * FROM receiving_numbers WHERE public_id = ? AND merchant_id = ?",
            [(string) $publicId, (int) $m['id']]
        );
        if (!$n) {
            return false;
        }
        Db::run("UPDATE receiving_numbers SET status = 'removed' WHERE id = ? AND status <> 'removed'", [(int) $n['id']]);
        return true;
    }

    public static function numberOut(array $n)
    {
        return [
            'id' => $n['public_id'], 'provider' => $n['provider'],
            'provider_name' => $n['provider_name'], 'provider_label' => self::providerName($n),
            'number' => $n['number'], 'account_name' => $n['account_name'],
        ];
    }

    public static function deviceOut(array $d)
    {
        $seen = $d['last_seen'] ? strtotime($d['last_seen']) : 0;
        return [
            'id' => $d['public_id'], 'label' => $d['label'], 'provider' => $d['provider'],
            'provider_name' => isset($d['provider_name']) ? $d['provider_name'] : '',
            'provider_label' => self::deviceProvider($d),
            'receiving_number' => $d['receiving_number'], 'receiving_name' => $d['receiving_name'],
            'extra_senders' => $d['extra_senders'], 'status' => $d['status'],
            'health' => $d['status'] !== 'active' ? 'revoked' : (!$seen ? 'never' : ($seen > time() - 1800 ? 'online' : 'quiet')),
            'last_seen' => $d['last_seen'], 'app_version' => $d['app_version'],
        ];
    }

    /**
     * Where to tell a customer to send the money, one entry per network.
     *
     * The list is its own thing now. Numbers typed on a listener before that
     * existed still count, so no merchant's payment page empties because of the
     * split, but a number only appears once however many ways it was entered.
     */
    public static function payTo($merchantId)
    {
        $out = $seen = [];
        foreach (self::numbers($merchantId) as $n) {
            $seen[$n['number']] = true;
            $out[] = ['number' => $n['number'], 'name' => $n['account_name'], 'provider' => $n['provider_label']];
        }
        foreach (Db::rows("SELECT * FROM devices WHERE merchant_id = ? AND status = 'active' ORDER BY id", [(int) $merchantId]) as $d) {
            $number = trim((string) $d['receiving_number']);
            if ($number === '' || isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;
            $out[] = ['number' => $number, 'name' => $d['receiving_name'], 'provider' => self::providerName($d)];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Intents
    // ---------------------------------------------------------------

    public static function createIntent(array $m, array $in)
    {
        foreach (['payer_phone', 'payer_name', 'reference'] as $field) {
            if (isset($in[$field]) && !is_string($in[$field])) {
                return ['error' => ['code' => 'bad_request', 'message' => 'Payer details and reference must be text values.']];
            }
        }
        $rawAmount = $in['amount'] ?? null;
        $amount = is_scalar($rawAmount) && is_numeric($rawAmount) ? (float) $rawAmount : 0;
        $key = Parser::msisdnKey($in['payer_phone'] ?? '', Parser::msisdnDigitsFor($m['dial_code']));
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['payer_name'] ?? '')));
        $reference = trim((string) ($in['reference'] ?? ''));
        if (!is_finite($amount) || $amount <= 0 || $amount > 999999999999.99
            || abs($amount - round($amount, 2)) > 0.000001) {
            return ['error' => ['code' => 'bad_amount', 'message' => 'Use a positive amount with at most two decimal places.']];
        }
        if ($key === '') {
            return ['error' => ['code' => 'bad_payer_phone', 'message' => 'Enter the full number the money will be sent from.']];
        }
        if ($reference === '') {
            return ['error' => ['code' => 'reference_required', 'message' => 'A reference is required.']];
        }
        if (strlen($reference) > 100 || strlen($name) > 100) {
            return ['error' => ['code' => 'invalid_length', 'message' => 'Reference and payer name must each be at most 100 bytes.']];
        }
        $metadata = json_encode($in['metadata'] ?? new stdClass());
        if ($metadata === false || strlen($metadata) > 16384) {
            return ['error' => ['code' => 'bad_metadata', 'message' => 'Metadata must be valid JSON no larger than 16 KB.']];
        }
        // A listener has to exist, or nothing can ever report this payment. It does
        // not need a receiving number: that is only how a customer is told where to
        // send money, and payTo() leaves out a listener that has none.
        $listening = Db::row("SELECT COUNT(*) c FROM devices WHERE merchant_id = ? AND status = 'active'", [(int) $m['id']]);
        if ((int) $listening['c'] === 0) {
            return ['error' => ['code' => 'no_device', 'message' => 'No listener phone is set up for this merchant yet.']];
        }

        $now = self::now();
        // The same request again (a reload, a second tap) is the same intent.
        $open = Db::row(
            "SELECT * FROM intents WHERE merchant_id = ? AND reference = ? AND payer_key = ? AND amount = ? AND status = 'waiting' AND expires_at > ? ORDER BY id DESC LIMIT 1",
            [(int) $m['id'], $reference, $key, $amount, $now]
        );
        if ($open) {
            if ($name !== '' && $open['payer_name'] !== $name) {
                Db::run("UPDATE intents SET payer_name = ? WHERE id = ?", [substr($name, 0, 100), (int) $open['id']]);
                $open['payer_name'] = $name;
            }
            $intent = $open;
        } else {
            Db::run(
                "INSERT INTO intents (public_id, merchant_id, amount, currency, payer_msisdn, payer_key, payer_name, reference, metadata, status, created_at, expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?, 'waiting', ?, ?)",
                [self::newId('pi'), (int) $m['id'], $amount, $m['currency'], $m['dial_code'] . $key, $key, $name, $reference, $metadata, $now, date('Y-m-d H:i:s', time() + self::INTENT_HOURS * 3600)]
            );
            $intent = Db::row("SELECT * FROM intents WHERE id = ?", [Db::lastId()]);
        }

        // They may have paid before asking. Money already sitting unmatched
        // under this number, for this amount, is theirs.
        $early = Db::row(
            "SELECT id FROM payments WHERE merchant_id = ? AND status = 'unmatched' AND kind = 'credit' AND reversed = 0 AND payer_key = ? AND amount = ? AND received_at > ? ORDER BY id DESC LIMIT 1",
            [(int) $m['id'], $key, $amount, date('Y-m-d H:i:s', time() - self::INTENT_HOURS * 3600)]
        );
        if ($early) {
            self::matchPayment((int) $early['id']);
            $intent = Db::row("SELECT * FROM intents WHERE id = ?", [(int) $intent['id']]);
        }
        return ['intent' => self::intentOut($intent), 'pay_to' => self::payTo($m['id'])];
    }

    public static function intentOut(array $i)
    {
        $status = ($i['status'] === 'waiting' && $i['expires_at'] <= self::now()) ? 'expired' : $i['status'];
        $out = [
            'id' => $i['public_id'], 'status' => $status, 'amount' => (float) $i['amount'], 'currency' => $i['currency'],
            'payer_phone' => $i['payer_msisdn'], 'payer_name' => $i['payer_name'], 'reference' => $i['reference'],
            'metadata' => json_decode((string) $i['metadata'], true) ?: new stdClass(),
            'created_at' => $i['created_at'], 'expires_at' => $i['expires_at'], 'payment' => null,
        ];
        if ((int) $i['payment_id'] > 0) {
            $p = Db::row("SELECT * FROM payments WHERE id = ?", [(int) $i['payment_id']]);
            $out['payment'] = $p ? self::paymentOut($p) : null;
        }
        return $out;
    }

    public static function paymentOut(array $p, $withMessage = false)
    {
        $out = [
            'id' => $p['public_id'], 'source' => $p['source'], 'provider' => $p['provider'],
            'provider_label' => Parser::providerLabel($p['provider']),
            'transaction_id' => strpos($p['trx_id'], 'raw:') === 0 ? '' : $p['trx_id'],
            'kind' => $p['kind'], 'amount' => (float) $p['amount'], 'currency' => $p['currency'],
            'payer_phone' => $p['payer_msisdn'], 'payer_name' => $p['payer_name'],
            'status' => $p['status'], 'hold_reason' => $p['hold_reason'], 'match_rule' => $p['match_rule'],
            'name_check' => $p['name_check'], 'reference' => $p['reference'],
            'reversed' => (int) $p['reversed'] === 1, 'received_at' => $p['received_at'],
        ];
        if ($withMessage) {
            $out['message'] = $p['raw_message'];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // A message arrives from a listener phone
    // ---------------------------------------------------------------

    /** Returns ignored, duplicate, recorded or reversal. Private messages are dropped, never stored. */
    /**
     * What the last ingest() read out of the message, and which payment it became.
     * Held here so the message can be filed next to the reading without changing
     * what ingest returns, which the tests and the phone both rely on.
     */
    public static $lastRead = [];

    /** A silence longer than this is an outage, not the gap between two reports. */
    const GAP_MINUTES = 12;

    /**
     * Notes that this listener is reporting. Extends the stretch it is already in,
     * or opens a new one after a silence, so the history stays small: a phone that
     * never drops out is a single row however long it runs.
     */
    public static function reporting(array $device)
    {
        $now = self::now();
        try {
            $last = Db::row("SELECT id, to_at FROM device_uptime WHERE device_id = ? ORDER BY id DESC LIMIT 1", [(int) $device['id']]);
            if ($last && strtotime($last['to_at']) >= time() - self::GAP_MINUTES * 60) {
                Db::run("UPDATE device_uptime SET to_at = ?, reports = reports + 1 WHERE id = ?", [$now, (int) $last['id']]);
                return;
            }
            Db::run("INSERT INTO device_uptime (merchant_id, device_id, from_at, to_at, reports) VALUES (?,?,?,?,1)",
                [(int) $device['merchant_id'], (int) $device['id'], $now, $now]);
        } catch (Throwable $e) {
            // History is worth having, never worth losing a payment over.
            error_log('device_uptime: ' . $e->getMessage());
        }
        if (random_int(1, 500) === 1) {
            Db::run("DELETE FROM device_uptime WHERE to_at < ?", [date('Y-m-d H:i:s', time() - 90 * 86400)]);
        }
    }

    /**
     * When a listener was not reporting, newest first. Built from the gaps between
     * stretches, plus the silence since the last one if it is still going on.
     */
    public static function outages($merchantId, $deviceId = 0, $sinceDays = 7)
    {
        $args = [(int) $merchantId, date('Y-m-d H:i:s', time() - $sinceDays * 86400)];
        $where = 'merchant_id = ? AND to_at > ?';
        if ($deviceId) { $where .= ' AND device_id = ?'; $args[] = (int) $deviceId; }
        $rows = Db::rows("SELECT device_id, from_at, to_at FROM device_uptime WHERE $where ORDER BY device_id, id", $args);
        $out = [];
        $previous = [];
        foreach ($rows as $row) {
            $id = (int) $row['device_id'];
            if (isset($previous[$id])) {
                $gap = strtotime($row['from_at']) - strtotime($previous[$id]);
                if ($gap > self::GAP_MINUTES * 60) {
                    $out[] = ['device_id' => $id, 'from' => $previous[$id], 'to' => $row['from_at'], 'minutes' => (int) round($gap / 60), 'ongoing' => false];
                }
            }
            $previous[$id] = $row['to_at'];
        }
        // Still quiet right now counts as an outage that has not ended.
        foreach ($previous as $id => $lastSeen) {
            $silent = time() - strtotime($lastSeen);
            if ($silent > self::GAP_MINUTES * 60) {
                $out[] = ['device_id' => $id, 'from' => $lastSeen, 'to' => null, 'minutes' => (int) round($silent / 60), 'ongoing' => true];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['from'], $a['from']));
        return $out;
    }

    /**
     * Files a reported message, whatever became of it. A message the gateway could
     * not read used to leave no trace, so nobody could see what a network actually
     * sends, and "I paid and nothing happened" had no answer.
     */
    public static function fileMessage(array $device, $sender, $body, $sentAt, $outcome)
    {
        $read = self::$lastRead;
        try {
            Db::run(
                "INSERT INTO device_messages (merchant_id, device_id, sender, body, sms_time, received_at, outcome, payment_id,
                    read_trx, read_amount, read_currency, read_name, read_msisdn)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [(int) $device['merchant_id'], (int) $device['id'], substr((string) $sender, 0, 100), (string) $body,
                 $sentAt ? date('Y-m-d H:i:s', (int) $sentAt) : null, self::now(), substr((string) $outcome, 0, 20),
                 (int) ($read['payment_id'] ?? 0), substr((string) ($read['trx_id'] ?? ''), 0, 64), (float) ($read['amount'] ?? 0),
                 substr((string) ($read['currency'] ?? ''), 0, 5), substr((string) ($read['payer_name'] ?? ''), 0, 100),
                 substr((string) ($read['payer_msisdn'] ?? ''), 0, 20)]
            );
        } catch (Throwable $e) {
            // Filing a message must never cost a payment. The payment is already
            // recorded by this point; losing the copy of the text is the lesser harm.
            error_log('device_messages: ' . $e->getMessage());
        }
        self::$lastRead = [];
        // Old messages hold payer names and numbers, so they do not accumulate for ever.
        if (random_int(1, 200) === 1) {
            Db::run("DELETE FROM device_messages WHERE received_at < ?", [date('Y-m-d H:i:s', time() - 90 * 86400)]);
        }
    }

    public static function ingest(array $device, $sender, $body, $sentAt = null)
    {
        self::$lastRead = [];
        if (!is_string($body) || strlen($body) > 16000 || !is_string($sender) || strlen($sender) > 100) {
            return 'ignored';
        }
        $extra = [];
        if (trim($device['extra_senders']) !== '') {
            $extra[$device['provider']] = array_filter(array_map('trim', explode(',', $device['extra_senders'])));
        }
        // "Other" accepts only this device's configured senders, including a
        // sender which also appears in a built-in country list.
        $provider = Parser::providerForSender($sender, $device['provider'] === 'other' ? '' : $device['dial_code'], $extra);
        if ($provider === '' || $provider !== $device['provider']) {
            // The phone let it through but this listener is not set up to accept that
            // sender. Saying so is the difference between the owner seeing the problem
            // and the message vanishing with a tick beside it.
            return 'unknown_sender';
        }
        $p = Parser::parseMessage($provider, $body, (string) ($device['merchant_currency'] ?? ''));
        self::$lastRead = $p;
        if ($p['kind'] === 'other') {
            return 'not_a_payment';
        }
        $mid = (int) $device['merchant_id'];
        $rkey = $device['receiving_key'] !== '' ? $device['receiving_key'] : 'D' . (int) $device['id'];

        if ($p['kind'] === 'reversal') {
            if ($p['trx_id'] === '') {
                return 'ignored';
            }
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $pay = Db::row("SELECT * FROM payments WHERE merchant_id = ? AND provider = ? AND receiving_key = ? AND trx_id = ? FOR UPDATE", [$mid, $provider, $rkey, $p['trx_id']]);
                if (!$pay) {
                    // Reversals can reach the listener before the original
                    // credit. Reserve that exact receipt identity so its later
                    // arrival cannot activate a package. No amount is inferred.
                    Db::run(
                        "INSERT INTO payments (public_id, merchant_id, device_id, source, provider, receiving_key, trx_id, kind, currency, sender, raw_message, parser, sms_time, received_at, status, hold_reason, reversed, reversed_at)
                         VALUES (?,?,?, 'direct_number', ?,?,?, 'reversal', ?,?,?,?,?,?, 'unmatched', 'reversal_before_receipt', 1, ?)",
                        [self::newId('pay'), $mid, (int) $device['id'], $provider, $rkey, $p['trx_id'], (string) ($device['merchant_currency'] ?? ''),
                            substr($sender, 0, 40), $body, $p['parser'], $sentAt ? date('Y-m-d H:i:s', $sentAt) : null, self::now(), self::now()]
                    );
                    $pdo->commit();
                    return 'reversal';
                }
                if ((int) $pay['reversed'] === 1) {
                    $pdo->rollBack();
                    return 'ignored';
                }
                Db::run("UPDATE payments SET reversed = 1, reversed_at = ? WHERE id = ?", [self::now(), (int) $pay['id']]);
                $pay['reversed'] = 1;
                $deliveryId = self::queueEvent($mid, 'payment.reversed', ['payment' => self::paymentOut($pay, true)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            self::deliver($deliveryId);
            return 'reversal';
        }

        // A message with no readable transaction ID is still stored once and
        // only once, keyed on its own text, and left for a person to read.
        $trx = $p['trx_id'] !== '' ? $p['trx_id'] : 'raw:' . sha1($provider . '|' . $body);
        try {
            Db::run(
                "INSERT INTO payments (public_id, merchant_id, device_id, source, provider, receiving_key, trx_id, kind, amount, currency, payer_msisdn, payer_key,
                                       payer_name, sender, raw_message, parser, sms_time, received_at, status, hold_reason)
                 VALUES (?,?,?, 'direct_number', ?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'unmatched', ?)",
                [
                    self::newId('pay'), $mid, (int) $device['id'], $provider, $rkey, $trx, $p['kind'], $p['amount'], $p['currency'],
                    $p['payer_msisdn'], Parser::msisdnKey($p['payer_msisdn'], Parser::msisdnDigitsFor($device['dial_code'])), $p['payer_name'],
                    substr((string) $sender, 0, 40), (string) $body, $p['parser'], $sentAt ? date('Y-m-d H:i:s', $sentAt) : null, self::now(),
                    $p['kind'] === 'unread' ? 'unread' : '',
                ]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // A previous attempt may have committed the receipt and then
                // failed while linking it. Resume safely on listener retry.
                $existing = Db::row("SELECT id FROM payments WHERE merchant_id = ? AND provider = ? AND receiving_key = ? AND trx_id = ?", [$mid, $provider, $rkey, $trx]);
                if ($existing) {
                    if ($p['kind'] === 'credit') self::completeReversedReceipt((int) $existing['id'], $p, $device, $sender, $body, $sentAt);
                    self::matchPayment((int) $existing['id']);
                }
                return 'duplicate'; // the phone re-sent something already delivered
            }
            throw $e;
        }
        $id = Db::lastId();
        // So the filed message can point at the payment it became.
        self::$lastRead['payment_id'] = $id;
        if ($p['kind'] !== 'credit' || !self::matchPayment($id)) {
            $pay = Db::row("SELECT * FROM payments WHERE id = ?", [$id]);
            self::emit($mid, 'payment.unmatched', [
                'payment' => self::paymentOut($pay, true),
                'known_references' => self::knownReferences($mid, $pay['payer_key']),
            ]);
        }
        return 'recorded';
    }

    /** Fill an earlier reversal notice with its eventual credit, without matching it. */
    private static function completeReversedReceipt($paymentId, array $parsed, array $device, $sender, $body, $sentAt)
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pay = Db::row("SELECT * FROM payments WHERE id = ? FOR UPDATE", [$paymentId]);
            if (!$pay || $pay['kind'] !== 'reversal' || (int) $pay['reversed'] !== 1) {
                $pdo->rollBack();
                return;
            }
            Db::run(
                "UPDATE payments SET kind = 'credit', amount = ?, currency = ?, payer_msisdn = ?, payer_key = ?, payer_name = ?, sender = ?, raw_message = ?, parser = ?, sms_time = ?, hold_reason = 'reversed' WHERE id = ?",
                [$parsed['amount'], $parsed['currency'], $parsed['payer_msisdn'], Parser::msisdnKey($parsed['payer_msisdn'], Parser::msisdnDigitsFor($device['dial_code'])),
                    $parsed['payer_name'], substr($sender, 0, 40), $body . "\n[Earlier reversal notice]\n" . $pay['raw_message'], $parsed['parser'],
                    $sentAt ? date('Y-m-d H:i:s', $sentAt) : null, $paymentId]
            );
            $pay = Db::row("SELECT * FROM payments WHERE id = ?", [$paymentId]);
            $deliveryId = self::queueEvent((int) $pay['merchant_id'], 'payment.reversed', ['payment' => self::paymentOut($pay, true)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::deliver($deliveryId);
    }

    /** References this payer number has paid for before. The merchant uses it to recognise a renewal. */
    public static function knownReferences($merchantId, $payerKey)
    {
        if ($payerKey === '') {
            return [];
        }
        return array_column(Db::rows("SELECT reference FROM payers WHERE merchant_id = ? AND payer_key = ? ORDER BY last_seen DESC", [(int) $merchantId, $payerKey]), 'reference');
    }

    private static function hold($paymentId, $reason)
    {
        Db::run("UPDATE payments SET hold_reason = ? WHERE id = ? AND hold_reason <> ?", [$reason, (int) $paymentId, $reason]);
    }

    /** True when the payment found its intent. */
    public static function matchPayment($paymentId)
    {
        $pay = Db::row("SELECT * FROM payments WHERE id = ?", [(int) $paymentId]);
        if (!$pay || $pay['status'] !== 'unmatched' || $pay['kind'] !== 'credit' || (int) $pay['reversed'] === 1) {
            return false;
        }
        if ($pay['payer_key'] === '') {
            self::hold($pay['id'], 'no_number');
            return false;
        }
        $intents = Db::rows(
            "SELECT * FROM intents WHERE merchant_id = ? AND payer_key = ? AND status = 'waiting' AND expires_at > ? AND amount = ? ORDER BY id DESC",
            [(int) $pay['merchant_id'], $pay['payer_key'], self::now(), $pay['amount']]
        );
        if (!$intents) {
            self::hold($pay['id'], self::knownReferences($pay['merchant_id'], $pay['payer_key']) ? 'known_payer_no_intent' : 'no_waiting_intent');
            return false;
        }
        // A newer browser request is not evidence that it owns this payment.
        if (count(array_unique(array_column($intents, 'reference'))) > 1) {
            self::hold($pay['id'], 'ambiguous_intents');
            return false;
        }
        return self::link($pay, $intents[0], 'number');
    }

    private static function link(array $pay, array $intent, $rule)
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // Lock both sides: two different receipts must never overwrite the
            // same paid intent, and a reversal must not race an activation.
            $pay = Db::row("SELECT * FROM payments WHERE id = ? FOR UPDATE", [(int) $pay['id']]);
            $intent = Db::row("SELECT * FROM intents WHERE id = ? FOR UPDATE", [(int) $intent['id']]);
            if (!$pay || !$intent || $pay['status'] !== 'unmatched' || $pay['kind'] !== 'credit'
                || (int) $pay['reversed'] !== 0 || $intent['status'] !== 'waiting'
                || (int) $intent['payment_id'] !== 0 || $intent['expires_at'] <= self::now()
                || (int) $pay['merchant_id'] !== (int) $intent['merchant_id']
                || abs((float) $pay['amount'] - (float) $intent['amount']) >= 0.005
                || $pay['payer_key'] === '' || !hash_equals($pay['payer_key'], $intent['payer_key'])) {
                $pdo->rollBack();
                return false;
            }
            $nameCheck = Parser::nameMatches($intent['payer_name'], $pay['payer_name']);
            if ($rule === 'number') {
                $candidates = Db::rows("SELECT reference FROM intents WHERE merchant_id = ? AND payer_key = ? AND status = 'waiting' AND expires_at > ? AND amount = ? FOR UPDATE", [(int) $pay['merchant_id'], $pay['payer_key'], self::now(), $pay['amount']]);
                if (count(array_unique(array_column($candidates, 'reference'))) > 1) {
                    self::hold($pay['id'], 'ambiguous_intents');
                    $pdo->commit();
                    return false;
                }
            }
            $others = Db::row("SELECT COUNT(*) c FROM payers WHERE merchant_id = ? AND payer_key = ? AND reference <> ?", [(int) $pay['merchant_id'], $pay['payer_key'], $intent['reference']]);
            if ((int) $others['c'] > 0 && $nameCheck !== true) {
                self::hold($pay['id'], 'number_held_by_another_reference');
                $pdo->commit();
                return false;
            }
            Db::run(
                "UPDATE payments SET status = 'matched', hold_reason = '', match_rule = ?, name_check = ?, intent_id = ?, reference = ?, matched_at = ? WHERE id = ?",
                [$rule, $nameCheck === true ? 'match' : ($nameCheck === false ? 'mismatch' : 'none'), (int) $intent['id'], $intent['reference'], self::now(), (int) $pay['id']]
            );
            Db::run("UPDATE intents SET status = 'paid', payment_id = ? WHERE id = ?", [(int) $pay['id'], (int) $intent['id']]);
            self::remember($pay, $intent['reference']);
            $intent = Db::row("SELECT * FROM intents WHERE id = ?", [(int) $intent['id']]);
            // The durable event and payment commit together. Network delivery
            // happens only after the database transaction has committed.
            $deliveryId = self::queueEvent((int) $pay['merchant_id'], 'payment.matched', ['intent' => self::intentOut($intent)]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::deliver($deliveryId);
        return true;
    }

    private static function remember(array $pay, $reference)
    {
        if ($pay['payer_key'] === '' || $reference === '') {
            return;
        }
        Db::run(
            "INSERT INTO payers (merchant_id, payer_key, reference, last_name, last_seen) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), last_seen = VALUES(last_seen)",
            [(int) $pay['merchant_id'], $pay['payer_key'], $reference, $pay['payer_name'], self::now()]
        );
    }

    /**
     * The merchant takes an unmatched payment for one of its references: a
     * person assigned it by hand, or the merchant recognised a renewal. It
     * succeeds once. A second attempt is told who already has it.
     */
    public static function assign(array $m, $paymentPublicId, $reference, $rule = 'manual')
    {
        if (!is_string($reference)) {
            return ['error' => ['code' => 'bad_request', 'message' => 'A reference must be a text value.']];
        }
        $pay = Db::row("SELECT * FROM payments WHERE merchant_id = ? AND public_id = ?", [(int) $m['id'], $paymentPublicId]);
        $reference = trim((string) $reference);
        if (!$pay) {
            return ['error' => ['code' => 'not_found', 'message' => 'Payment not found.']];
        }
        if ($reference === '' || strlen($reference) > 100) {
            return ['error' => ['code' => 'reference_required', 'message' => 'A reference is required.']];
        }
        if ($pay['kind'] !== 'credit') {
            return ['error' => ['code' => 'needs_review', 'message' => 'Only a payment that was read as money coming in can be assigned.']];
        }
        if ((int) $pay['reversed'] === 1) {
            return ['error' => ['code' => 'reversed', 'message' => 'This payment was reversed by the network.']];
        }
        if ($pay['status'] === 'matched') {
            return $pay['reference'] === $reference
                ? ['payment' => self::paymentOut($pay, true)]
                : ['error' => ['code' => 'already_matched', 'message' => 'This payment already belongs to ' . $pay['reference'] . '.']];
        }
        $rule = in_array($rule, ['manual', 'remembered'], true) ? $rule : 'manual';
        Db::run("UPDATE payments SET status = 'matched', hold_reason = '', match_rule = ?, reference = ?, matched_at = ? WHERE id = ? AND status = 'unmatched' AND reversed = 0", [$rule, $reference, self::now(), (int) $pay['id']]);
        $pay = Db::row("SELECT * FROM payments WHERE id = ?", [(int) $pay['id']]);
        if ((int) $pay['reversed'] === 1) {
            return ['error' => ['code' => 'reversed', 'message' => 'This payment was reversed by the network.']];
        }
        if ($pay['reference'] !== $reference) {
            return ['error' => ['code' => 'already_matched', 'message' => 'This payment already belongs to ' . $pay['reference'] . '.']];
        }
        self::remember($pay, $reference);
        return ['payment' => self::paymentOut($pay, true)];
    }

    /**
     * "I paid but nothing happened." The payer gives the transaction ID from
     * their own message. An invented ID finds no payment. A real one must
     * also match the intent's paying number. A name alone is not proof; a
     * receipt with no payer number needs the provider to review and assign it.
     */
    public static function claim(array $m, $intentPublicId, $trxId, $ip)
    {
        if (!is_string($trxId)) {
            return ['error' => ['code' => 'bad_transaction_id', 'message' => 'Please enter the transaction ID from your payment message.']];
        }
        $mid = (int) $m['id'];
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        $trxId = strtoupper(preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $trxId));
        $tries = Db::row("SELECT COUNT(*) c FROM claims WHERE merchant_id = ? AND ip = ? AND ok = 0 AND created_at > ?", [$mid, $ip, date('Y-m-d H:i:s', time() - 600)]);
        if ((int) $tries['c'] >= 5) {
            return ['error' => ['code' => 'too_many_attempts', 'message' => 'Too many attempts. Please wait a few minutes.']];
        }
        $fail = function ($code, $msg) use ($mid, $ip, $trxId) {
            Db::run("INSERT INTO claims (merchant_id, ip, trx_id, ok, created_at) VALUES (?,?,?,0,?)", [$mid, $ip, substr($trxId, 0, 64), date('Y-m-d H:i:s')]);
            return ['error' => ['code' => $code, 'message' => $msg]];
        };

        $intent = Db::row("SELECT * FROM intents WHERE merchant_id = ? AND public_id = ?", [$mid, $intentPublicId]);
        if (!$intent) {
            return ['error' => ['code' => 'intent_not_found', 'message' => 'Please start the payment again, then claim it.']];
        }
        if (strlen($trxId) < 6 || strlen($trxId) > 64) {
            return $fail('bad_transaction_id', 'Please enter the transaction ID from your payment message.');
        }
        $matches = Db::rows(
            "SELECT * FROM payments WHERE merchant_id = ? AND trx_id = ? AND kind = 'credit' AND received_at > ? ORDER BY id DESC LIMIT 2",
            [$mid, $trxId, date('Y-m-d H:i:s', time() - self::CLAIM_DAYS * 86400)]
        );
        if (!$matches) {
            return $fail('payment_not_found', 'We have not received that payment yet. If you have just paid, wait a moment and try again.');
        }
        if (count($matches) !== 1) {
            return $fail('needs_review', 'More than one receipt has that transaction ID. Please contact your provider.');
        }
        $pay = $matches[0];
        if ((int) $pay['reversed'] === 1) {
            return $fail('reversed', 'That payment was reversed. Please contact your provider.');
        }
        if ($pay['payer_key'] === '' || !hash_equals($pay['payer_key'], $intent['payer_key'])) {
            return $fail('not_yours', 'The paying number could not be verified for this purchase. Please contact your provider.');
        }
        // The amount decides. The receipt's currency is whatever the network wrote on
        // money that landed in the merchant's own account, so it is recorded and shown
        // rather than required to equal the label on their profile.
        if (abs((float) $pay['amount'] - (float) $intent['amount']) >= 0.005) {
            return $fail('amount_mismatch', 'That payment does not match the price you chose. Please contact your provider.');
        }
        if ($pay['status'] === 'matched') {
            // Claiming twice gives the same answer, never a second credit.
            if ($pay['reference'] === $intent['reference'] && (int) $pay['intent_id'] > 0) {
                return ['intent' => self::intentOut(Db::row("SELECT * FROM intents WHERE id = ?", [(int) $pay['intent_id']]))];
            }
            return $fail('already_used', 'That payment is already in use. Please contact your provider.');
        }
        if ($intent['status'] !== 'waiting' || (int) $intent['payment_id'] !== 0 || $intent['expires_at'] <= self::now()) {
            return $fail('intent_not_available', 'This purchase is paid or expired. Please start a new purchase to claim an unused receipt.');
        }
        if (!self::link($pay, $intent, 'claim')) {
            return ['error' => ['code' => 'needs_review', 'message' => 'This payment needs your provider to confirm it.']];
        }
        Db::run("INSERT INTO claims (merchant_id, ip, trx_id, ok, created_at) VALUES (?,?,?,1,?)", [$mid, $ip, $trxId, self::now()]);
        return ['intent' => self::intentOut(Db::row("SELECT * FROM intents WHERE id = ?", [(int) $intent['id']]))];
    }

    // ---------------------------------------------------------------
    // Webhooks
    // ---------------------------------------------------------------

    /**
     * Every event is stored first, then sent. A merchant that is down gets it
     * later from bin/deliver-webhooks.php. Merchants must treat the event id
     * as the thing to de-duplicate on: an event may arrive more than once.
     */
    public static function emit($merchantId, $type, array $data)
    {
        self::deliver(self::queueEvent($merchantId, $type, $data));
    }

    private static function queueEvent($merchantId, $type, array $data)
    {
        $eventId = self::newId('evt');
        $payload = json_encode(['id' => $eventId, 'type' => $type, 'created' => time(), 'data' => $data]);
        Db::run(
            "INSERT INTO webhook_deliveries (event_id, merchant_id, type, payload, status, attempts, next_attempt_at, created_at) VALUES (?,?,?,?, 'pending', 0, ?, ?)",
            [$eventId, (int) $merchantId, $type, $payload, self::now(), self::now()]
        );
        return Db::lastId();
    }

    public static function sign($secret, $timestamp, $body)
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function deliver($deliveryId)
    {
        $d = Db::row("SELECT w.*, m.webhook_url, m.webhook_secret FROM webhook_deliveries w JOIN merchants m ON m.id = w.merchant_id WHERE w.id = ?", [(int) $deliveryId]);
        if (!$d || $d['status'] === 'sent') {
            return true;
        }
        $code = 0;
        // No address yet: nothing is sent, and the event waits for one like any other retry.
        if ((string) $d['webhook_url'] !== '') {
            $t = time();
            $res = self::httpPost($d['webhook_url'], $d['payload'], [
                'Content-Type: application/json',
                'X-Gateway-Event: ' . $d['type'],
                'X-Gateway-Signature: t=' . $t . ',v1=' . self::sign($d['webhook_secret'], $t, $d['payload']),
            ], 10);
            $code = $res['code'];
        }
        $attempts = (int) $d['attempts'] + 1;
        if ($code >= 200 && $code < 300) {
            Db::run("UPDATE webhook_deliveries SET status = 'sent', attempts = ?, last_code = ? WHERE id = ?", [$attempts, $code, (int) $d['id']]);
            return true;
        }
        // 1m, 5m, 15m, 1h, 4h, then daily, for about three days.
        $waits = [60, 300, 900, 3600, 14400, 86400, 86400, 86400];
        $status = $attempts > count($waits) ? 'failed' : 'pending';
        $next = date('Y-m-d H:i:s', time() + ($waits[min($attempts, count($waits)) - 1]));
        Db::run("UPDATE webhook_deliveries SET status = ?, attempts = ?, last_code = ?, next_attempt_at = ? WHERE id = ?", [$status, $attempts, $code, $next, (int) $d['id']]);
        return false;
    }

    public static function httpPost($url, $body, array $headers, $timeout)
    {
        try {
            $target = WebhookTarget::resolve($url, (bool) Config::get('allow_insecure_webhooks', false));
        } catch (InvalidArgumentException $e) {
            return ['code' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        $out = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => max(1, min(30, (int) $timeout)),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (Config::get('allow_insecure_webhooks', false) ? CURLPROTO_HTTP : 0),
            CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$out) {
                if (strlen($out) + strlen($chunk) > 65536) return 0;
                $out .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (!$target['literal']) {
            $address = strpos($target['address'], ':') !== false ? '[' . $target['address'] . ']' : $target['address'];
            curl_setopt($ch, CURLOPT_RESOLVE, [$target['host'] . ':' . $target['port'] . ':' . $address]);
        }
        $ok = curl_exec($ch);
        $code = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $ok === false ? '' : $out];
    }
}
