<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Channels;

use CraftCms\Cms\Support\Env;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Switchboard\Settings;
use RuntimeException;

use function CraftCms\Cms\t;

/**
 * Outbound HTTP for webhooks, Slack, Discord and Teams, safe against SSRF:
 *
 * - HTTPS on port 443 only, no credentials in the URL, no redirects followed;
 * - the host is resolved once, every address must be public (no loopback,
 *   private, link-local, CGNAT, multicast or reserved ranges, IPv4-mapped IPv6
 *   included), and the request is pinned to the checked address so DNS can't
 *   be swapped between the check and the request (rebinding);
 * - short connect and total timeouts.
 *
 * A `host` or `host:port` listed in the `allowedHosts` setting skips the
 * public-address, HTTPS and port checks — for a local receiver in development.
 */
class SafeHttp
{
    public const string ENV_PATTERN = '/^\$SWITCHBOARD_[A-Z0-9_]{1,64}$/D';

    public function __construct(private readonly Settings $settings) {}

    /**
     * A route may be a whole-value reference to an environment variable named
     * `SWITCHBOARD_*` (e.g. `$SWITCHBOARD_SLACK_URL`), so secrets stay out of the
     * database. Other variables can't be read, so a route can't leak them.
     */
    public static function isEnvReference(string $value): bool
    {
        return (bool) preg_match(self::ENV_PATTERN, $value);
    }

    public static function resolveRoute(string $value): string
    {
        return self::isEnvReference($value) ? (string) Env::parse($value) : $value;
    }

    /**
     * Save-time check of a configured URL (env references are checked when sending).
     *
     * @param  list<string>  $hosts  Hosts this channel posts to (`*.example.com` matches subdomains); empty: any public host.
     */
    public function validateConfigured(string $to, array $hosts, string $what): ?string
    {
        if (self::isEnvReference($to)) {
            return null;
        }

        try {
            $this->check($to, $hosts, resolve: false);
        } catch (RuntimeException $e) {
            return t('Not a usable {what} URL: {reason}', ['what' => $what, 'reason' => $e->getMessage()], category: 'switchboard');
        }

        return null;
    }

    /**
     * POSTs a JSON body to a checked URL. Returns the response status.
     *
     * @param  list<string>  $hosts
     * @param  array<string, string>  $headers
     *
     * @throws RuntimeException with a message that's safe to log (host only, never the full URL).
     */
    public function postJson(string $url, string $body, array $hosts = [], array $headers = []): int
    {
        [$parts, $pin] = $this->check($url, $hosts, resolve: true);
        $host = $parts['host'];
        $timeout = max(1, min(30, $this->settings->webhookTimeout));

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'connect_timeout' => min(5, $timeout),
                'timeout' => $timeout,
                'curl' => $pin === null ? [] : [CURLOPT_RESOLVE => [$pin]],
            ])
                ->withHeaders($headers + ['User-Agent' => 'Switchboard (Craft CMS)'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn’t connect to {$host}.");
        }

        $status = $response->status();

        if ($status >= 300) {
            throw new RuntimeException("{$host} answered HTTP {$status}.");
        }

        return $status;
    }

    /**
     * @param  list<string>  $hosts
     * @return array{0: array{scheme: string, host: string, port: int}, 1: string|null} The URL parts and a CURLOPT_RESOLVE pin.
     */
    public function check(string $url, array $hosts, bool $resolve): array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            throw new RuntimeException('the URL is malformed');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('the URL is malformed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('credentials in the URL aren’t allowed');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('only https URLs are allowed');
        }

        $allowed = $this->isAllowlisted($host, $port);

        if (! $allowed && $hosts !== [] && ! self::hostMatches($host, $hosts)) {
            throw new RuntimeException('the host must be '.implode(' or ', $hosts));
        }

        if (! $allowed && ($scheme !== 'https' || $port !== 443)) {
            throw new RuntimeException('only https URLs on port 443 are allowed');
        }

        $result = ['scheme' => $scheme, 'host' => $host, 'port' => $port];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $allowed && ! self::isPublicIp($host)) {
                throw new RuntimeException('private and reserved addresses aren’t allowed');
            }

            return [$result, null];
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/D', $host)) {
            throw new RuntimeException('the host name is invalid');
        }

        if (! $resolve) {
            return [$result, null];
        }

        $ips = self::lookup($host);

        if ($ips === []) {
            throw new RuntimeException("{$host} doesn’t resolve");
        }

        if (! $allowed) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new RuntimeException("{$host} resolves to a private or reserved address");
                }
            }
        }

        $ip = $ips[0];

        return [$result, $host.':'.$port.':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip)];
    }

    public function isAllowlisted(string $host, int $port): bool
    {
        foreach ($this->settings->allowedHostList() as $entry) {
            if ($entry === $host || $entry === "{$host}:{$port}") {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    public static function hostMatches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1))) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** Globally routable unicast only. IPv4-mapped/compatible IPv6 is judged by its IPv4 part. */
    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // ::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::a.b.c.d (NAT64) → check the embedded IPv4 address.
            $prefix = substr($packed, 0, 12);
            if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === str_repeat("\0", 12) || $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
                $v4 = inet_ntop(substr($packed, 12));

                return $v4 !== false && self::isPublicIp($v4);
            }
            // 2002::/16 (6to4) embeds an IPv4 address too.
            if (str_starts_with($packed, "\x20\x02")) {
                $v4 = inet_ntop(substr($packed, 2, 4));

                return $v4 !== false && self::isPublicIp($v4);
            }
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $n = unpack('N', $packed)[1] ?? 0;
            foreach ([['100.64.0.0', 10], ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4], ['192.0.0.0', 24], ['0.0.0.0', 8]] as [$net, $bits]) {
                $base = unpack('N', (string) inet_pton($net))[1] ?? 0;
                $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
                if (($n & $mask) === ($base & $mask)) {
                    return false;
                }
            }
        } elseif (ord($packed[0]) === 0xFF) {
            return false; // multicast
        }

        return true;
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $ips = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            // /etc/hosts and other resolvers dns_get_record() doesn't consult.
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
