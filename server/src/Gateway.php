<?php

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

    public static function createMerchant($name, $country, $dialCode, $currency, $webhookUrl)
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        Db::run(
            "INSERT INTO merchants (public_id, name, country, dial_code, currency, webhook_url, webhook_secret, status, created_at)
             VALUES (?,?,?,?,?,?,?, 'active', ?)",
            [self::newId('mer'), substr($name, 0, 120), strtolower(substr($country, 0, 40)), preg_replace('/\D+/', '', $dialCode), strtoupper(substr($currency, 0, 5)), $webhookUrl, $secret, self::now()]
        );
        $id = Db::lastId();
        return ['merchant' => Db::row("SELECT * FROM merchants WHERE id = ?", [$id]), 'api_key' => self::issueApiKey($id), 'webhook_secret' => $secret];
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
        $provider = (string) ($in['provider'] ?? '');
        $number = preg_replace('/[^\d+]/', '', (string) ($in['receiving_number'] ?? ''));
        $name = trim((string) ($in['receiving_name'] ?? ''));
        // Networks we already know are picked from a list. Anywhere else in the
        // world the merchant names the sender their payment messages come from,
        // so no country has to wait for us before it can start.
        $extraSenders = trim((string) ($in['extra_senders'] ?? ''));
        if ($provider === 'other') {
            if ($extraSenders === '') {
                return ['error' => ['code' => 'sender_required', 'message' => 'Type the sender name your payment messages arrive from, exactly as your phone shows it.']];
            }
        } elseif (!array_key_exists($provider, Parser::defaultSenders($m['dial_code']))) {
            return ['error' => ['code' => 'unknown_provider', 'message' => 'Choose your mobile money network.']];
        }
        $rkey = Parser::msisdnKey($number, Parser::msisdnDigitsFor($m['dial_code']));
        if ($rkey === '') {
            return ['error' => ['code' => 'bad_number', 'message' => 'Enter the full number that receives the money.']];
        }
        if ($name === '') {
            return ['error' => ['code' => 'name_required', 'message' => 'Enter the name registered on that number.']];
        }
        $key = bin2hex(random_bytes(20));
        Db::run(
            "INSERT INTO devices (public_id, merchant_id, label, provider, receiving_number, receiving_key, receiving_name, extra_senders, key_hash, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?, 'active', ?)",
            [self::newId('dev'), (int) $m['id'], substr(trim((string) ($in['label'] ?? '')) ?: $name, 0, 80), $provider, substr($number, 0, 20), $rkey, substr($name, 0, 100), substr(trim((string) ($in['extra_senders'] ?? '')), 0, 255), hash('sha256', $key), self::now()]
        );
        $d = Db::row("SELECT * FROM devices WHERE id = ?", [Db::lastId()]);
        return ['device' => self::deviceOut($d), 'device_key' => $key];
    }

    public static function rotateDeviceKey(array $m, $publicId)
    {
        $key = bin2hex(random_bytes(20));
        $n = Db::run("UPDATE devices SET key_hash = ?, status = 'active' WHERE merchant_id = ? AND public_id = ?", [hash('sha256', $key), (int) $m['id'], $publicId]);
        return $n ? ['device_key' => $key] : null;
    }

    public static function revokeDevice(array $m, $publicId)
    {
        $d = Db::row("SELECT id FROM devices WHERE merchant_id = ? AND public_id = ?", [(int) $m['id'], $publicId]);
        if (!$d) {
            return false;
        }
        Db::run("UPDATE devices SET status = 'revoked' WHERE id = ? AND status <> 'revoked'", [(int) $d['id']]);
        return true;
    }

    public static function deviceOut(array $d)
    {
        $seen = $d['last_seen'] ? strtotime($d['last_seen']) : 0;
        return [
            'id' => $d['public_id'], 'label' => $d['label'], 'provider' => $d['provider'],
            'provider_label' => Parser::providerLabel($d['provider']),
            'receiving_number' => $d['receiving_number'], 'receiving_name' => $d['receiving_name'],
            'extra_senders' => $d['extra_senders'], 'status' => $d['status'],
            'health' => $d['status'] !== 'active' ? 'revoked' : (!$seen ? 'never' : ($seen > time() - 1800 ? 'online' : 'quiet')),
            'last_seen' => $d['last_seen'], 'app_version' => $d['app_version'],
        ];
    }

    public static function payTo($merchantId)
    {
        $out = [];
        foreach (Db::rows("SELECT * FROM devices WHERE merchant_id = ? AND status = 'active' ORDER BY id", [(int) $merchantId]) as $d) {
            $out[] = ['number' => $d['receiving_number'], 'name' => $d['receiving_name'], 'provider' => Parser::providerLabel($d['provider'])];
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Intents
    // ---------------------------------------------------------------

    public static function createIntent(array $m, array $in)
    {
        $amount = round((float) ($in['amount'] ?? 0), 2);
        $key = Parser::msisdnKey($in['payer_phone'] ?? '', Parser::msisdnDigitsFor($m['dial_code']));
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['payer_name'] ?? '')));
        $reference = trim((string) ($in['reference'] ?? ''));
        if ($amount <= 0) {
            return ['error' => ['code' => 'bad_amount', 'message' => 'The amount must be more than zero.']];
        }
        if ($key === '') {
            return ['error' => ['code' => 'bad_payer_phone', 'message' => 'Enter the full number the money will be sent from.']];
        }
        if ($reference === '') {
            return ['error' => ['code' => 'reference_required', 'message' => 'A reference is required.']];
        }
        if (!self::payTo($m['id'])) {
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
                [self::newId('pi'), (int) $m['id'], $amount, $m['currency'], $m['dial_code'] . $key, $key, substr($name, 0, 100), substr($reference, 0, 100), json_encode($in['metadata'] ?? new stdClass()), $now, date('Y-m-d H:i:s', time() + self::INTENT_HOURS * 3600)]
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
    public static function ingest(array $device, $sender, $body, $sentAt = null)
    {
        $extra = [];
        if (trim($device['extra_senders']) !== '') {
            $extra[$device['provider']] = array_filter(array_map('trim', explode(',', $device['extra_senders'])));
        }
        $provider = Parser::providerForSender($sender, $device['dial_code'], $extra);
        if ($provider === '') {
            return 'ignored';
        }
        $p = Parser::parseMessage($provider, $body, (string) ($device['merchant_currency'] ?? ''));
        if ($p['kind'] === 'other') {
            return 'ignored';
        }
        $mid = (int) $device['merchant_id'];
        $rkey = $device['receiving_key'] !== '' ? $device['receiving_key'] : 'D' . (int) $device['id'];

        if ($p['kind'] === 'reversal') {
            if ($p['trx_id'] === '') {
                return 'ignored';
            }
            $pay = Db::row("SELECT * FROM payments WHERE merchant_id = ? AND provider = ? AND receiving_key = ? AND trx_id = ?", [$mid, $provider, $rkey, $p['trx_id']]);
            if (!$pay || (int) $pay['reversed'] === 1) {
                return 'ignored';
            }
            Db::run("UPDATE payments SET reversed = 1, reversed_at = ? WHERE id = ?", [self::now(), (int) $pay['id']]);
            $pay['reversed'] = 1;
            self::emit($mid, 'payment.reversed', ['payment' => self::paymentOut($pay, true)]);
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
                return 'duplicate'; // the phone re-sent something already delivered
            }
            throw $e;
        }
        $id = Db::lastId();
        if ($p['kind'] !== 'credit' || !self::matchPayment($id)) {
            $pay = Db::row("SELECT * FROM payments WHERE id = ?", [$id]);
            self::emit($mid, 'payment.unmatched', [
                'payment' => self::paymentOut($pay, true),
                'known_references' => self::knownReferences($mid, $pay['payer_key']),
            ]);
        }
        return 'recorded';
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
        // Same number and amount more than once: the newest is the one on the payer's screen.
        return self::link($pay, $intents[0], 'number');
    }

    private static function link(array $pay, array $intent, $rule)
    {
        $nameCheck = Parser::nameMatches($intent['payer_name'], $pay['payer_name']);
        if ($rule === 'number') {
            // A number already known against a DIFFERENT reference cannot be
            // captured just by typing it somewhere else. Only a positive name
            // match lets it through.
            $others = Db::row("SELECT COUNT(*) c FROM payers WHERE merchant_id = ? AND payer_key = ? AND reference <> ?", [(int) $pay['merchant_id'], $pay['payer_key'], $intent['reference']]);
            if ((int) $others['c'] > 0 && $nameCheck !== true) {
                self::hold($pay['id'], 'number_held_by_another_reference');
                return false;
            }
        }
        $done = Db::run(
            "UPDATE payments SET status = 'matched', hold_reason = '', match_rule = ?, name_check = ?, intent_id = ?, reference = ?, matched_at = ? WHERE id = ? AND status = 'unmatched'",
            [$rule, $nameCheck === true ? 'match' : ($nameCheck === false ? 'mismatch' : 'none'), (int) $intent['id'], $intent['reference'], self::now(), (int) $pay['id']]
        );
        if (!$done) {
            return false; // another request matched it first
        }
        Db::run("UPDATE intents SET status = 'paid', payment_id = ? WHERE id = ?", [(int) $pay['id'], (int) $intent['id']]);
        self::remember($pay, $intent['reference']);
        $intent = Db::row("SELECT * FROM intents WHERE id = ?", [(int) $intent['id']]);
        self::emit((int) $pay['merchant_id'], 'payment.matched', ['intent' => self::intentOut($intent)]);
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
        $pay = Db::row("SELECT * FROM payments WHERE merchant_id = ? AND public_id = ?", [(int) $m['id'], $paymentPublicId]);
        $reference = trim((string) $reference);
        if (!$pay) {
            return ['error' => ['code' => 'not_found', 'message' => 'Payment not found.']];
        }
        if ($reference === '') {
            return ['error' => ['code' => 'reference_required', 'message' => 'A reference is required.']];
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
        Db::run("UPDATE payments SET status = 'matched', hold_reason = '', match_rule = ?, reference = ?, matched_at = ? WHERE id = ? AND status = 'unmatched'", [$rule, substr($reference, 0, 100), self::now(), (int) $pay['id']]);
        $pay = Db::row("SELECT * FROM payments WHERE id = ?", [(int) $pay['id']]);
        if ($pay['reference'] !== $reference) {
            return ['error' => ['code' => 'already_matched', 'message' => 'This payment already belongs to ' . $pay['reference'] . '.']];
        }
        self::remember($pay, $reference);
        return ['payment' => self::paymentOut($pay, true)];
    }

    /**
     * "I paid but nothing happened." The payer gives the transaction ID from
     * their own message. An invented ID finds no payment. A real one must
     * also be theirs: the paying number has to equal the intent's, or, where
     * the network sent no number, the name has to match.
     */
    public static function claim(array $m, $intentPublicId, $trxId, $ip)
    {
        $mid = (int) $m['id'];
        $trxId = strtoupper(preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $trxId));
        $tries = Db::row("SELECT COUNT(*) c FROM claims WHERE merchant_id = ? AND ip = ? AND ok = 0 AND created_at > ?", [$mid, $ip, date('Y-m-d H:i:s', time() - 600)]);
        if ($ip !== '' && (int) $tries['c'] >= 5) {
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
        if (strlen($trxId) < 6) {
            return $fail('bad_transaction_id', 'Please enter the transaction ID from your payment message.');
        }
        $pay = Db::row(
            "SELECT * FROM payments WHERE merchant_id = ? AND UPPER(trx_id) = ? AND kind = 'credit' AND received_at > ? ORDER BY id DESC LIMIT 1",
            [$mid, $trxId, date('Y-m-d H:i:s', time() - self::CLAIM_DAYS * 86400)]
        );
        if (!$pay) {
            return $fail('payment_not_found', 'We have not received that payment yet. If you have just paid, wait a moment and try again.');
        }
        if ((int) $pay['reversed'] === 1) {
            return $fail('reversed', 'That payment was reversed. Please contact your provider.');
        }
        if ($pay['status'] === 'matched') {
            // Claiming twice gives the same answer, never a second credit.
            if ($pay['reference'] === $intent['reference']) {
                return ['intent' => self::intentOut(Db::row("SELECT * FROM intents WHERE id = ?", [(int) ($pay['intent_id'] ?: $intent['id'])]))];
            }
            return $fail('already_used', 'That payment is already in use. Please contact your provider.');
        }
        if (abs((float) $pay['amount'] - (float) $intent['amount']) >= 0.005) {
            return $fail('amount_mismatch', 'That payment does not match the price you chose. Please contact your provider.');
        }
        $owns = $pay['payer_key'] !== ''
            ? hash_equals($pay['payer_key'], $intent['payer_key'])
            : Parser::nameMatches($intent['payer_name'], $pay['payer_name']) === true;
        if (!$owns) {
            return $fail('not_yours', 'That payment came from a different number. Start again and enter the number the money was sent from.');
        }
        Db::run("INSERT INTO claims (merchant_id, ip, trx_id, ok, created_at) VALUES (?,?,?,1,?)", [$mid, $ip, substr($trxId, 0, 64), self::now()]);
        if (!self::link($pay, $intent, 'claim')) {
            return ['error' => ['code' => 'needs_review', 'message' => 'This payment needs your provider to confirm it.']];
        }
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
        $eventId = self::newId('evt');
        $payload = json_encode(['id' => $eventId, 'type' => $type, 'created' => time(), 'data' => $data]);
        Db::run(
            "INSERT INTO webhook_deliveries (event_id, merchant_id, type, payload, status, attempts, next_attempt_at, created_at) VALUES (?,?,?,?, 'pending', 0, ?, ?)",
            [$eventId, (int) $merchantId, $type, $payload, self::now(), self::now()]
        );
        self::deliver(Db::lastId());
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
        if ($d['webhook_url'] !== '') {
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $out === false ? '' : $out];
    }
}
