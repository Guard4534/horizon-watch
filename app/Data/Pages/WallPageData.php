<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\SentNotificationData;
use App\Data\Monitoring\SeriesGridData;
use Spatie\LaravelData\Data;

class WallPageData extends Data
{
    public function __construct(
        public WallKpisData $kpis,
        /** @var array<int, EnvironmentData> */
        public array $environments,
        /** @var array<int, AlertData> */
        public array $anomalies,
        /** @var array<int, int> */
        public array $throughput,
        public SeriesGridData $grid,
        public int $jobsPerMinute,
        /** @var array<int, SentNotificationData> */
        public array $notifications,
        public int $applicationCount,
    ) {}
}
