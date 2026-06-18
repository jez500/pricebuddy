<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL is a publicly reachable http/https target and not an
 * internal, loopback, link-local or otherwise reserved host. Guards the
 * server-side scraper against SSRF (e.g. cloud metadata at 169.254.169.254,
 * internal services on RFC1918 ranges, or hostnames that resolve to them).
 */
class PublicHttpUrl implements ValidationRule
{
    /**
     * IPv4 ranges that must never be reached server-side.
     *
     * @var array<int, string>
     */
    protected array $blockedIpv4 = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // RFC6598 carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // RFC3927 link-local (incl. cloud metadata)
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
        '255.255.255.255/32', // broadcast
    ];

    /**
     * IPv6 ranges that must never be reached server-side.
     *
     * @var array<int, string>
     */
    protected array $blockedIpv6 = [
        '::1/128',      // loopback
        '::/128',       // unspecified
        '64:ff9b::/96', // NAT64
        '100::/64',     // discard-only
        '2001:db8::/32', // documentation
        'fc00::/7',     // unique local address
        'fe80::/10',    // link-local
        'ff00::/8',     // multicast
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            $fail('The :attribute must use the http or https scheme.');

            return;
        }

        $host = $this->normaliseHost($parts['host']);

        if ($host === '' || $this->isBlockedHostname($host)) {
            $fail('The :attribute must not point to an internal or reserved host.');

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHostIps($host);

        if ($ips === []) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                $fail('The :attribute must not point to an internal or reserved host.');

                return;
            }
        }
    }

    /**
     * Lowercase, strip IPv6 brackets, and convert IDN/punycode to ASCII so
     * unicode variants of blocked hosts cannot bypass the checks.
     */
    protected function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host, '[]'));

        if ($host !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        return $host;
    }

    protected function isBlockedHostname(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    /**
     * Resolve a hostname to its A and AAAA records.
     *
     * @return array<int, string>
     */
    protected function resolveHostIps(string $host): array
    {
        $ips = [];

        foreach (gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    protected function isPublicIp(string $ip): bool
    {
        $ip = $this->unwrapMappedIpv4($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($this->blockedIpv4 as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach ($this->blockedIpv6 as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Convert an IPv4-mapped IPv6 address (::ffff:1.2.3.4) to its IPv4 form so it
     * is checked against the IPv4 block list.
     */
    protected function unwrapMappedIpv4(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);

            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $tail;
            }

            $packed = @inet_pton($ip);

            if ($packed !== false && strlen($packed) === 16) {
                return inet_ntop(substr($packed, 12));
            }
        }

        return $ip;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);

        if ($ipPacked === false || $subnetPacked === false || strlen($ipPacked) !== strlen($subnetPacked)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipPacked, $subnetPacked, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainder) & 0xFF);

        return (ord($ipPacked[$bytes]) & ord($mask)) === (ord($subnetPacked[$bytes]) & ord($mask));
    }
}
