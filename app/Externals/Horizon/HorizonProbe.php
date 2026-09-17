<?php

namespace App\Externals\Horizon;

final readonly class HorizonProbe
{
    public function __construct(
        public string $status,
        public int $masterCount,
        public int $latencyMs,
    ) {}
}
