<?php

/** Validate at connection time, then pin DNS so validation and delivery agree. */
class WebhookTarget
{
    public static function resolve($url, $allowInsecure = false, $resolver = null)
    {
        if (!is_string($url) || strlen($url) > 255 || preg_match('/[\x00-\x20\x7f]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid webhook URL.');
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, $allowInsecure ? ['https', 'http'] : ['https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Use an HTTPS webhook without credentials or a fragment.');
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || strpos($host, '%') !== false) {
            throw new InvalidArgumentException('Invalid webhook host.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid webhook port.');
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($literal) {
            $addresses = [$host];
        } elseif ($resolver !== null) {
            $addresses = $resolver($host);
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach ($records ?: [] as $record) {
                if (isset($record['ip'])) $addresses[] = $record['ip'];
                if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
            }
        }
        if (!$addresses) {
            throw new InvalidArgumentException('Webhook host has no usable address.');
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)
                || (!$allowInsecure && !self::isPublicAddress($address))) {
                throw new InvalidArgumentException('Webhook host must resolve only to public addresses.');
            }
        }
        return ['host' => $host, 'port' => $port, 'address' => reset($addresses), 'literal' => $literal];
    }

    public static function isPublicAddress($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 4) {
            // PHP's flags do not cover shared, documentation or multicast space.
            $blocked = ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'];
        } else {
            // Accept global unicast only; exclude transition and documentation ranges.
            if (!self::inRange($packed, '2000::/3')) return false;
            $blocked = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        }
        foreach ($blocked as $range) {
            if (self::inRange($packed, $range)) return false;
        }
        return true;
    }

    private static function inRange($packed, $range)
    {
        [$network, $bits] = explode('/', $range);
        $network = inet_pton($network);
        if (strlen($packed) !== strlen($network)) return false;
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        return substr($packed, 0, $bytes) === substr($network, 0, $bytes)
            && (!$rest || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $rest))) === 0);
    }
}
