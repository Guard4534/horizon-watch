<?php

namespace App\Externals\Horizon\Dns;

final class SystemResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (gethostbynamel($host) ?: [] as $ip) {
            $addresses[] = $ip;
        }

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
