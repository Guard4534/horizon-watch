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
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($parts['host'] ?? ''));
        $name = rtrim(trim($urlHost, '[]'), '.');

        if (! in_array($scheme, ['http', 'https'], true) || $name === '' || in_array($name, self::BLOCKED_NAMES, true)) {
            throw new HorizonReadFailed(ReadingError::Blocked);
        }

        $addresses = $this->resolver->resolve($name);

        if ($addresses === []) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        $allowed = [];

        foreach ($addresses as $address) {
            $ip = $this->normalize($address);

            if ($ip === null || IpUtils::checkIp($ip, $this->blockedRanges())) {
                throw new HorizonReadFailed(ReadingError::Blocked);
            }

            $allowed[] = $ip;
        }

        return new ResolvedTarget(
            host: $urlHost,
            port: (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            ip: $this->preferred($allowed),
        );
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
     * An IPv4 address mapped into IPv6 (::ffff:a.b.c.d, in any spelling) is
     * judged as the IPv4 address it reaches.
     */
    private function normalize(string $address): ?string
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return null;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        return (string) inet_ntop($binary);
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
