<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class FailedJobData extends Data
{
    public function __construct(
        public string $job,
        public string $queue,
        public string $exception,
        public int $tries,
        public int $minutesAgo,
    ) {}
}
