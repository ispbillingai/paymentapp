<?php

/**
 * Reads mobile money confirmation messages. Pure functions, no database:
 * which sender names belong to which network, what a message says, how a
 * phone number and a name are compared.
 *
 * The three matching jobs are kept apart on purpose:
 *   the NUMBER binds   (whose payment it is, last N digits must be equal)
 *   the NAME confirms  (one name, one letter of tolerance, never the identity)
 *   the AMOUNT checks  (must equal the amount asked for, never the identity)
 */
class Parser
{
    /** Subscriber digits compared per country dial code. Nine unless listed. */
    private static $msisdnDigits = [
        '234' => 10, // Nigeria
    ];

    /**
     * Known mobile money senders per country dial code. Keys are provider
     * codes; values are sender names as the phone shows them, compared with
     * case and punctuation removed. Anything not listed is never parsed.
     */
    private static $senders = [
        '256' => [
            'mtn_ug'    => ['MTNMobMoney', 'MTN MoMo', 'MTNMoMo', 'MobileMoney', 'MTN Mobile Money', 'MoMo'],
            'airtel_ug' => ['AirtelMoney', 'Airtel Money', 'AIRTELMONEY'],
        ],
        '233' => [
            'mtn_gh'     => ['MobileMoney', 'MTN MoMo', 'MTNMoMo', 'MoMo'],
            'telecel_gh' => ['TelecelCash', 'Telecel Cash', 'VodaCash', 'Vodafone Cash', 'T-Cash'],
            'at_gh'      => ['ATMoney', 'AT Money', 'AirtelTigo', 'AirtelTigo Money'],
        ],
    ];

    private static $providerLabels = [
        'mtn_ug'     => 'MTN MoMo',
        'airtel_ug'  => 'Airtel Money',
        'mtn_gh'     => 'MTN MoMo',
        'telecel_gh' => 'Telecel Cash',
        'at_gh'      => 'AT Money',
        'other'      => 'Mobile money',
    ];

    /** Currency words a confirmation message may use, mapped to the ISO code. */
    private static $currencies = [
        'UGX' => 'UGX', 'USH' => 'UGX', 'SHS' => 'UGX',
        'GHS' => 'GHS', 'GHC' => 'GHS',
        'TZS' => 'TZS', 'TSH' => 'TZS',
        'RWF' => 'RWF', 'NGN' => 'NGN', 'XAF' => 'XAF', 'ZMW' => 'ZMW', 'MWK' => 'MWK',
    ];

    // ---------------------------------------------------------------
    // Numbers
    // ---------------------------------------------------------------

    public static function msisdnDigitsFor($dialCode)
    {
        $dialCode = preg_replace('/\D+/', '', (string) $dialCode);
        return isset(self::$msisdnDigits[$dialCode]) ? self::$msisdnDigits[$dialCode] : 9;
    }

    /**
     * The comparable part of a phone number: every non digit removed, then the
     * last N digits. 0755822013, 755822013, +256 755 822013 and 256755822013
     * all become 755822013. Returns '' when there are fewer than N digits, so a
     * half typed number can never match anything.
     */
    public static function msisdnKey($raw, $digits = 9)
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) < $digits) {
            return '';
        }
        return substr($d, -$digits);
    }

    // ---------------------------------------------------------------
    // Names
    // ---------------------------------------------------------------

    /**
     * Does the one name the customer typed appear in the network's sender name?
     *
     * Case is ignored, word order is ignored, and one letter of difference is
     * allowed (a swapped pair counts as one, so Jhon matches John). Names
     * shorter than four letters must match exactly, otherwise Roy would pass
     * for Joy.
     *
     * Returns true, false, or null when there is nothing to compare (the
     * message carried no name, or the customer typed none). Null is not a
     * failure and must not be flagged as one.
     */
    public static function nameMatches($typed, $networkName)
    {
        $typedWords = self::nameWords($typed);
        $netWords = self::nameWords($networkName);
        if (!$typedWords || !$netWords) {
            return null;
        }
        // One name is all we ask for. If they typed more, any one of them
        // appearing is enough.
        foreach ($typedWords as $t) {
            foreach ($netWords as $n) {
                if ($t === $n) {
                    return true;
                }
                if (strlen($t) >= 4 && strlen($n) >= 4 && self::editDistance($t, $n) <= 1) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function nameWords($s)
    {
        $s = strtolower(trim((string) $s));
        $s = preg_replace('/[^a-z\s\'\-]/', ' ', $s);
        $s = str_replace(["'", '-'], '', $s);
        $out = [];
        foreach (preg_split('/\s+/', $s) as $w) {
            if (strlen($w) >= 2) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /** Optimal string alignment distance: insert, delete, substitute, swap. */
    private static function editDistance($a, $b)
    {
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > 1) {
            return 2;
        }
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i] = [$i];
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$la][$lb];
    }

    // ---------------------------------------------------------------
    // Senders and parsing
    // ---------------------------------------------------------------

    public static function providerLabel($code)
    {
        return isset(self::$providerLabels[$code]) ? self::$providerLabels[$code] : (string) $code;
    }

    /** Default allowed sender names for a country, as provider => [names]. */
    public static function defaultSenders($dialCode)
    {
        $dialCode = preg_replace('/\D+/', '', (string) $dialCode);
        return isset(self::$senders[$dialCode]) ? self::$senders[$dialCode] : [];
    }

    private static function senderKey($s)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    }

    /**
     * Which provider does this sender name belong to? '' when it is not an
     * allowed mobile money sender, including every ordinary phone number: a
     * sender that is mostly digits is a person, not a network.
     */
    public static function providerForSender($sender, $dialCode, array $extra = [])
    {
        $key = self::senderKey($sender);
        if ($key === '' || preg_match('/^\d{5,}$/', $key)) {
            return '';
        }
        $map = self::defaultSenders($dialCode);
        foreach ($extra as $prov => $names) {
            $map[$prov] = array_merge(isset($map[$prov]) ? $map[$prov] : [], (array) $names);
        }
        foreach ($map as $prov => $names) {
            foreach ($names as $n) {
                if (self::senderKey($n) === $key) {
                    return $prov;
                }
            }
        }
        return '';
    }

    /**
     * Read one confirmation message.
     *
     * kind is one of:
     *   credit    money received, every essential field read
     *   reversal  a reversal notice that names an earlier transaction
     *   other     an allowed sender, but not money coming in (a payment the
     *             owner made, a balance notice, a promotion). Never recorded
     *             as a payment.
     *   unread    looks like money coming in but a field could not be read
     *             with certainty. Stored raw for the ISP, never guessed at.
     */
    public static function parseMessage($provider, $body, $merchantCurrency = '')
    {
        $out = [
            'kind' => 'other', 'provider' => $provider, 'trx_id' => '', 'amount' => 0.0,
            'currency' => '', 'payer_msisdn' => '', 'payer_name' => '', 'parser' => $provider . ':1',
        ];
        $text = trim(preg_replace('/\s+/', ' ', (string) $body));
        if ($text === '') {
            return $out;
        }

        // The transaction ID is either labelled, or leads the message in the
        // "0000012345678 Confirmed." style.
        $trx = '';
        if (preg_match('/(?:financial\s+transaction\s+id|transaction\s+id|trans(?:action)?\.?\s*id|txn\.?\s*id|\bTID)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9.\-]{5,30})/i', $text, $m)) {
            $trx = rtrim($m[1], '.-');
        } elseif (preg_match('/^([A-Z0-9]{8,24})\s+confirmed\b/i', $text, $m)) {
            $trx = $m[1];
        }

        if (preg_match('/\brevers(?:ed|al)\b/i', $text)) {
            $out['kind'] = 'reversal';
            $out['trx_id'] = $trx;
            return $out;
        }

        // Money going out, or anything else that is not a credit.
        if (!preg_match('/\b(?:you\s+have\s+)?received\b/i', $text)
            || preg_match('/\b(?:you\s+have\s+)?(?:sent|paid|withdrawn|bought|transferred)\b/i', $text)) {
            return $out;
        }

        $out['kind'] = 'unread';
        $out['trx_id'] = $trx;

        // Amount: the first currency figure after the word received. Only
        // known currency words count, so "TID 98765432101" is never an amount.
        // The merchant's own currency counts too, so a country we have never
        // seen a message from still reads correctly.
        $currencies = self::$currencies;
        $own = strtoupper(trim((string) $merchantCurrency));
        if (preg_match('/^[A-Z]{2,5}$/', $own) && !isset($currencies[$own])) {
            $currencies[$own] = $own;
        }
        $curWords = implode('|', array_keys($currencies));
        if (preg_match('/received.{0,60}?\b(' . $curWords . ')\.?\s*([\d][\d,]*(?:\.\d{1,2})?)/i', $text, $m)) {
            $out['currency'] = $currencies[strtoupper($m[1])];
            $out['amount'] = (float) str_replace(',', '', $m[2]);
        }

        // The "from" part runs until the next sentence or the date. It holds
        // the payer's number and name in either order. Some networks leave
        // the number out altogether.
        if (preg_match('/\bfrom\s+(.{3,90}?)(?:\s+on\s+\d|\.\s|\.$|\s+Bal\b|\s+Your\b|\s+Reason\b|\s+Current\b|\s+Available\b|\s+Reference\b|\s+Transaction\b|\s+TID\b|$)/i', $text, $m)) {
            $from = $m[1];
            if (preg_match('/\+?\d[\d\s]{7,15}\d/', $from, $n)) {
                $out['payer_msisdn'] = preg_replace('/\D+/', '', $n[0]);
                $from = str_replace($n[0], ' ', $from);
            }
            $name = trim(preg_replace('/[^A-Za-z\s\'\-]/', ' ', $from));
            $name = trim(preg_replace('/\s+/', ' ', $name), " -'");
            if (strlen($name) >= 2) {
                $out['payer_name'] = substr($name, 0, 100);
            }
        }

        // A credit needs its transaction ID and its amount. The payer's number
        // is wanted but not required: without it the payment is still real
        // money, it just cannot be matched automatically.
        if ($out['trx_id'] !== '' && $out['amount'] > 0) {
            $out['kind'] = 'credit';
        }
        return $out;
    }
}
