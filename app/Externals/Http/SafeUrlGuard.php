<?php

namespace App\Externals\Http;

use App\Enums\ReadingError;
use App\Externals\Http\Dns\Resolver;
use Illuminate\Container\Attributes\Config;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\IpUtils;

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
     * @throws UrlRefused
     */
    public function check(#[SensitiveParameter] string $url): ResolvedTarget
    {
        if (preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw new UrlRefused(ReadingError::Blocked);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $urlHost = strtolower((string) ($parts['host'] ?? ''));
        $name = rtrim(trim($urlHost, '[]'), '.');

        if (! in_array($scheme, ['http', 'https'], true)
            || $name === ''
            || ! $this->usableHost($urlHost)
            || in_array($name, self::BLOCKED_NAMES, true)) {
            throw new UrlRefused(ReadingError::Blocked);
        }

        $addresses = $this->resolver->resolve($name);

        if ($addresses === []) {
            throw new UrlRefused(ReadingError::Unreachable);
        }

        $pins = [];

        foreach ($addresses as $address) {
            $inspected = $this->inspect($address);

            if ($inspected === null) {
                throw new UrlRefused(ReadingError::Blocked);
            }

            [$pin, $judged] = $inspected;

            foreach ($judged as $ip) {
                if (IpUtils::checkIp($ip, $this->blockedRanges())) {
                    throw new UrlRefused(ReadingError::Blocked);
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

    private function embeddedIpv4(string $binary): ?string
    {
        $tail = substr($binary, 12);

        return match (true) {
            str_starts_with($binary, str_repeat("\0", 12)) => in_array($tail, ["\0\0\0\0", "\0\0\0\1"], true) ? null : $tail,
            str_starts_with($binary, str_repeat("\0", 8)."\xff\xff\0\0") => $tail,
            str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => $tail,
            str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),
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
