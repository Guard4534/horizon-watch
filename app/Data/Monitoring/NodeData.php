<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class NodeData extends Data
{
    public function __construct(
        public string $hostname,
        public EnvironmentStatus $status,
        public int $workers,
        public int $supervisorCount,
        public int $queueCount,
        // Since the last successful reading that listed this node.
        public int $seenSecondsAgo,
    ) {}
}
