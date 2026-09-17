<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class WallKpisData extends Data
{
    public function __construct(
        // Active or degraded: Horizon is working, perhaps slowly.
        public int $environmentsUp,
        // Active only, the phone's "Up": degraded ones are its "Issues".
        public int $environmentsActive,
        public int $environmentsTotal,
        public int $openAnomalies,
        public int $pendingTotal,
        // The plain sum of every environment's count, each over its own
        // window: never scaled to a common period.
        public int $failedTotal,
        // The window every counted environment shares, in minutes; null
        // when they differ, and the label says so instead of naming one.
        public ?int $failedWindowMinutes,
        // Environments whose failures per hour (count × 60 / window) are
        // above the default jobs.failed_per_hour threshold. A rate, because
        // a week's count against a day's threshold would warn forever.
        public int $environmentsOverFailedRate,
    ) {}
}
