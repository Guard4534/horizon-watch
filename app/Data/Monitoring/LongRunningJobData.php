<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class LongRunningJobData extends Data
{
    public function __construct(
        public string $job,
        public string $queue,
        public int $elapsedSeconds,
        public string $startedAt,
    ) {}
}
