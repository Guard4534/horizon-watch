<?php

namespace App\Externals\Horizon\Dns;

final class SystemResolver implements Resolver
{
    /**
     * dns_get_record() asks the DNS servers only, so it never sees
     * /etc/hosts: "localhost" in some images and "host.docker.internal"
     * under Sail would not resolve. gethostbynamel() goes through the
     * system resolver but knows only IPv4. The guard checks the union.
     */
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
