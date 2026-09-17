<?php

namespace App\Externals\Horizon\Data;

use Carbon\CarbonImmutable;

final readonly class HorizonFailedJob
{
    public function __construct(
        public string $name,
        public string $queue,
        public string $exception,
        public int $attempts,
        public CarbonImmutable $failedAt,
    ) {}
}
