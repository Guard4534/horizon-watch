<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class QueueData extends Data
{
    public function __construct(
        public string $name,
        public ?string $supervisor,
        public int $workers,
        public int $pending,
        public int $waitSeconds,
        public ?float $runtimeSeconds,
        public EnvironmentStatus $status,
    ) {}
}
