<?php

declare(strict_types=1);

namespace App\Http\Client;

use Illuminate\Http\Client\ConnectionException;

/**
 * Port of LagoHttpClient::AddressGuard
 * (lib/lago_http_client/lago_http_client/address_guard.rb): blocks outbound
 * requests to private / special-purpose IP ranges (SSRF guard).
 */
class AddressGuard
{
    /**
     * Rails BLOCKED_RANGES — IPv4 and IPv6 special-purpose ranges, verbatim.
     *
     * @return list<string>
     */
    public static function blockedRanges(): array
    {
        return [
            // IPv4
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.88.99.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
            // IPv6 special-purpose ranges inside global unicast
            '2001::/23',
            '2001:db8::/32',
            '2002::/16',
            '3fff::/20',
        ];
    }

    /**
     * Rails: enabled? — the LAGO_WEBHOOK_ALLOW_PRIVATE_URLS override.
     */
    public static function enabled(): bool
    {
        return ! filter_var(env('LAGO_WEBHOOK_ALLOW_PRIVATE_URLS'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Rails: resolve! — resolves the host and raises BlockedAddressError when
     * any of its addresses is blocked; raises ConnectionException (Rails'
     * SocketError) when the host does not resolve.
     */
    public static function resolve(string $host): string
    {
        $addresses = self::lookup($host);

        if ($addresses === []) {
            throw new ConnectionException("getaddrinfo: no address for {$host}");
        }

        foreach ($addresses as $address) {
            if (self::blockedIp($address)) {
                throw new BlockedAddressError($host);
            }
        }

        return $addresses[0];
    }

    /**
     * Rails uses Addrinfo.getaddrinfo(host, nil, nil, :STREAM). PHP's
     * dns_get_record covers A/AAAA; gethostbyname is the A-record fallback
     * for names the resolver serves outside the DNS (e.g. /etc/hosts).
     *
     * @return list<string>
     */
    public static function lookup(string $host): array
    {
        $host = strtolower(trim($host));

        // Literal IPs skip the resolver, exactly like getaddrinfo.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['type'], $record['ip']) && in_array($record['type'], ['A', 'AAAA'], true)) {
                    $addresses[] = $record['ip'];
                }
            }
        }

        if ($addresses === []) {
            $resolved = @gethostbyname($host);
            if (is_string($resolved) && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $resolved;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Rails: blocked_ip? — NAT64 well-known prefix unwraps to the embedded
     * IPv4; anything outside IPv6 global unicast (2000::/3) is blocked; the
     * blocked CIDR ranges block the rest.
     */
    public static function blockedIp(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return true;
        }

        // DNS64 synthesizes these for IPv4-only hosts, so the embedded IPv4
        // address is what gets checked.
        if (self::inCidr($packed, '64:ff9b::/96')) {
            $packed = substr($packed, 12, 4);
        }

        $isIpv6 = strlen($packed) === 16;

        // Anything outside global unicast (loopback, mapped IPv4, ULA,
        // link-local, SRv6, multicast...) is blocked.
        if ($isIpv6 && ! self::inCidr($packed, '2000::/3')) {
            return true;
        }

        foreach (self::blockedRanges() as $range) {
            if (self::inCidr($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    /** inet_pton-byte CIDR containment — no ipaddr gem, same semantics. */
    public static function inCidr(string $packed, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);
        $subnetPacked = @inet_pton($subnet);
        if ($subnetPacked === false) {
            return false;
        }

        if (strlen($subnetPacked) !== strlen($packed)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $fullBytes = intdiv($prefixLength, 8);
        $remainderBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($packed, 0, $fullBytes) !== substr($subnetPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits > 0 && $fullBytes < strlen($packed)) {
            $mask = 0xFF << (8 - $remainderBits) & 0xFF;

            return ((ord($packed[$fullBytes]) ^ ord($subnetPacked[$fullBytes])) & $mask) === 0;
        }

        return true;
    }
}
