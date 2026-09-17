<?php

namespace App\Externals\Horizon;

use App\Enums\ReadingError;
use App\Externals\Horizon\Dns\Resolver;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Illuminate\Container\Attributes\Config;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The only place that knows which addresses the panel may not contact.
 * Private networks are allowed by default because the typical Horizon is an
 * internal one; cloud metadata endpoints never are.
 */
final readonly class SafeUrlGuard
{
    private const array ALWAYS_BLOCKED = [
        '169.254.0.0/16',
        'fe80::/10',
        'fd00:ec2::254',
        '100.100.100.200',
        '0.0.0.0/8',
        '224.0.0.0/4',
        'ff00::/8',
        '::/128',
        // Local-use NAT64 prefixes place the IPv4 address according to a
        // prefix length the guard cannot know, so the range is refused
        // rather than guessed at.
        '64:ff9b:1::/48',
    ];

    private const array PRIVATE_NETWORKS = [
        '127.0.0.0/8',
        '::1/128',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    private const array BLOCKED_NAMES = [
        'metadata.google.internal',
        'metadata',
    ];

    public function __construct(
        private Resolver $resolver,
        #[Config('horizon-watch.block_private_networks', false)]
        private bool $blockPrivateNetworks = false,
    ) {}

    /**
     * Every address the name resolves to must pass, not only the one that
     * gets pinned: a DNS answer mixing a public and a metadata address is
     * refused as a whole.
     *
     * @throws HorizonReadFailed
     */
    public function check(#[SensitiveParameter] string $url): ResolvedTarget
    {
        // Anything but printable ASCII is refused before parsing: Guzzle
        // throws its own exception on invalid UTF-8, and libcurl would
        // ignore a raw UTF-8 --resolve entry, or decode a percent-encoded
        // host itself, and look the name up again.
        if (preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new HorizonReadFailed(ReadingError::Blocked);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($parts['host'] ?? ''));
        $name = rtrim(trim($urlHost, '[]'), '.');

        if (! in_array($scheme, ['http', 'https'], true)
            || $name === ''
            || ! $this->usableHost($urlHost)
            || in_array($name, self::BLOCKED_NAMES, true)) {
            throw new HorizonReadFailed(ReadingError::Blocked);
        }

        $addresses = $this->resolver->resolve($name);

        if ($addresses === []) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        $pins = [];

        foreach ($addresses as $address) {
            $inspected = $this->inspect($address);

            if ($inspected === null) {
                throw new HorizonReadFailed(ReadingError::Blocked);
            }

            [$pin, $judged] = $inspected;

            foreach ($judged as $ip) {
                if (IpUtils::checkIp($ip, $this->blockedRanges())) {
                    throw new HorizonReadFailed(ReadingError::Blocked);
                }
            }

            $pins[] = $pin;
        }

        return new ResolvedTarget(
            host: $urlHost,
            port: (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            ip: $this->preferred($pins),
        );
    }

    /**
     * A name made of letters, digits, dots, hyphens and underscores (the
     * underscore for container service names), or a bracketed IPv6 literal.
     *
     * A name whose last label reads as a number is parsed as an IPv4
     * address by glibc and libcurl, in forms the pin does not cover
     * (2130706433, 0177.0.0.1, 127.1, 0x7f.1): only a canonical dotted
     * quad is accepted there.
     */
    private function usableHost(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']')
                && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (preg_match('/^[a-z0-9._-]+$/', $host) !== 1) {
            return false;
        }

        $labels = explode('.', rtrim($host, '.'));

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return true;
    }

    /**
     * The address to pin and every address it stands for. An IPv4 address
     * mapped into IPv6 (::ffff:a.b.c.d, in any spelling) reaches that IPv4
     * host itself, so it is judged and pinned as IPv4. The IPv6 forms that
     * carry an IPv4 address for a translator or a relay are judged twice:
     * as themselves and as the IPv4 address they lead to.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private function inspect(string $address): ?array
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        $ip = (string) inet_ntop($binary);
        $embedded = strlen($binary) === 16 ? $this->embeddedIpv4($binary) : null;

        return [$ip, $embedded === null ? [$ip] : [$ip, (string) inet_ntop($embedded)]];
    }

    /**
     * The four bytes of IPv4 inside an IPv6 address, for the prefixes that
     * define where they sit.
     */
    private function embeddedIpv4(string $binary): ?string
    {
        $tail = substr($binary, 12);

        return match (true) {
            // IPv4-compatible (::a.b.c.d, deprecated). :: and ::1 are IPv6
            // addresses of their own and are judged as such.
            str_starts_with($binary, str_repeat("\0", 12)) => in_array($tail, ["\0\0\0\0", "\0\0\0\1"], true) ? null : $tail,
            // SIIT, ::ffff:0:a.b.c.d.
            str_starts_with($binary, str_repeat("\0", 8)."\xff\xff\0\0") => $tail,
            // NAT64 well-known prefix, 64:ff9b::/96.
            str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => $tail,
            // 6to4, 2002:a.b.c.d::/48.
            str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),
            // Teredo, 2001:0::/32: the client address, with its bits inverted.
            str_starts_with($binary, "\x20\x01\x00\x00") => $tail ^ "\xff\xff\xff\xff",
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function blockedRanges(): array
    {
        return $this->blockPrivateNetworks
            ? [...self::ALWAYS_BLOCKED, ...self::PRIVATE_NETWORKS]
            : self::ALWAYS_BLOCKED;
    }

    /**
     * IPv4 first: a container network without an IPv6 route would turn a
     * pinned AAAA answer into "unreachable".
     *
     * @param  non-empty-list<string>  $addresses
     */
    private function preferred(array $addresses): string
    {
        foreach ($addresses as $ip) {
            if (! str_contains($ip, ':')) {
                return $ip;
            }
        }

        return $addresses[0];
    }
}
