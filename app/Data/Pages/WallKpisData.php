<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class WallKpisData extends Data
{
    public function __construct(
        public int $environmentsUp,
        public int $environmentsActive,
        public int $environmentsTotal,
        public int $openAnomalies,
        public int $pendingTotal,
        public int $failedTotal,
        public ?int $failedWindowMinutes,
        public int $environmentsOverFailedRate,
    ) {}
}
