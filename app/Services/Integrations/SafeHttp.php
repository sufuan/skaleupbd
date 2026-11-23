<?php

// Goes in: app/Services/Integrations/SafeHttp.php (new file).
// Stages 3, 5, 6 and 7 (WooCommerce calls) must send every outbound request to a seller's site through this class.

namespace App\Services\Integrations;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Outbound HTTP to a seller-controlled address, with the SSRF rules applied:
 *  - HTTPS only, standard port only, no credentials in the URL, domain names only (no IP literals)
 *  - the name is resolved here and EVERY address must be public (see IpGuard)
 *  - the connection is pinned to the address we checked (CURLOPT_RESOLVE), so DNS cannot change between
 *    the check and the request
 *  - redirects are never followed, 10 second limit, certificate verification stays on
 * Fails closed: if the curl extension is missing it refuses to send.
 * Only call this from queued jobs, never from a web request.
 */
final class SafeHttp
{
    private Closure $resolver;

    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host): array => self::resolveHost($host);
    }

    /** Static checks only (no DNS). Safe to run inside a web request. Returns the lower-cased host. */
    public static function validateShape(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new UnsafeUrlException('Enter a full address that starts with https://');
        }

        $p = parse_url($url);
        if ($p === false || ! isset($p['scheme'], $p['host'])) {
            throw new UnsafeUrlException('Enter a full address that starts with https://');
        }
        if (strtolower($p['scheme']) !== 'https') {
            throw new UnsafeUrlException('The address must start with https://');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new UnsafeUrlException('The address must not contain a username or password.');
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            throw new UnsafeUrlException('Only the standard secure port is allowed. Remove the port number from the address.');
        }

        $host = strtolower(rtrim($p['host'], '.'));
        $bare = trim($host, '[]');
        if (str_contains($host, '[') || filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            throw new UnsafeUrlException('Use your website domain name, not an IP address.');
        }

        $labelOk = '[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?';
        $tldOk = '(?!\d+$)[a-z0-9-]{2,63}';
        if (strlen($host) > 253 || ! preg_match('/^('.$labelOk.'\.)+'.$tldOk.'$/', $host)) {
            throw new UnsafeUrlException('That does not look like a valid website address.');
        }

        foreach (['.local', '.localhost', '.internal', '.lan', '.home.arpa', '.intranet', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new UnsafeUrlException('That address points to a private or internal network, which is not allowed.');
            }
        }

        return $host;
    }

    /** @return array{0:string,1:list<string>} host and the list of verified public addresses */
    public function resolveSafe(string $url): array
    {
        $host = self::validateShape($url);

        $ips = ($this->resolver)($host);
        if (! is_array($ips) || $ips === []) {
            throw new UnsafeUrlException('We could not find your website address. Check that the domain name is spelled correctly.');
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic((string) $ip)) {
                throw new UnsafeUrlException('That address points to a private or internal network, which is not allowed.');
            }
        }

        // Prefer IPv4: many shared hosts have no IPv6 route.
        $ips = array_values($ips);
        usort($ips, static fn ($a, $b) => (str_contains($a, ':') <=> str_contains($b, ':')));

        return [$host, $ips];
    }

    public function post(string $url, string $body, array $headers = []): Response
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new UnsafeUrlException('Secure sending is not available on this server.');
        }

        [$host, $ips] = $this->resolveSafe($url);
        $ip = $ips[0];
        $pinned = str_contains($ip, ':') ? '['.$ip.']' : $ip; // (verify) IPv6 bracket form in CURLOPT_RESOLVE on the production curl build

        $maxBytes = (int) config('integrations.webhook.max_response_bytes', 262144);

        return Http::withOptions([
            'allow_redirects' => false,
            'timeout' => (int) config('integrations.webhook.timeout', 10),
            'connect_timeout' => (int) config('integrations.webhook.connect_timeout', 5),
            'curl' => [
                CURLOPT_RESOLVE => [$host.':443:'.$pinned],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ],
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new \RuntimeException('Response too large');
                }
            },
        ])->withHeaders($headers)->withBody($body, 'application/json')->post($url);
    }

    /** @return list<string> */
    public static function resolveHost(string $host): array
    {
        $ips = [];

        // Trailing dot: ask for the exact name, never a search-domain variant.
        $records = @dns_get_record($host.'.', DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (isset($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (isset($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = @gethostbynamel($host.'.');
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }
}
