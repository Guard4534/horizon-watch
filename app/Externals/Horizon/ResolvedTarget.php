<?php

namespace App\Externals\Horizon;

final readonly class ResolvedTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    public function curlResolve(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return $this->host.':'.$this->port.':'.$ip;
    }
}
