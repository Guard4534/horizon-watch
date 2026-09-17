<?php

namespace App\Externals\Horizon\Data;

use Carbon\CarbonImmutable;

final readonly class HorizonPendingJob
{
    public function __construct(
        public string $name,
        public string $queue,
        public string $status,
        public ?CarbonImmutable $reservedAt,
    ) {}
}
