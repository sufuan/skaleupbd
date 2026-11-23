<?php

// Goes in: app/Services/Integrations/IpGuard.php (new file). Pure PHP, no framework dependency.

namespace App\Services\Integrations;

/**
 * Decides whether an IP address is a normal public internet address.
 * Anything private, loopback, link-local (169.254.169.254 cloud metadata), shared, reserved,
 * multicast, or an IPv6 form that can wrap an IPv4 address is refused. Unparseable input is refused.
 */
final class IpGuard
{
    private const V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const V6 = [
        '::/96',            // unspecified, loopback, deprecated IPv4-compatible
        '::ffff:0:0/96',    // IPv4-mapped
        '64:ff9b::/96',     // NAT64
        '100::/64',         // discard
        '2001::/32',        // Teredo
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'fec0::/10',        // deprecated site-local
        'ff00::/8',         // multicast
    ];

    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }

        $ranges = strlen($bin) === 4 ? self::V4 : self::V6;
        foreach ($ranges as $cidr) {
            if (self::inRange($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        $bits = (int) $bits;

        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;

        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
