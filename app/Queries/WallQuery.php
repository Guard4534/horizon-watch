<?php

namespace App\Queries;

use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\WallKpisData;
use App\Data\Pages\WallPageData;
use App\Enums\AlertState;
use App\Enums\EnvironmentStatus;
use App\Enums\SeriesRange;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class WallQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team): WallPageData
    {
        $environments = $this->monitoring->environments($team);
        usort($environments, EnvironmentData::compareBySeverityThenPending(...));

        $anomalies = $this->monitoring->alerts($team, AlertState::Open);
        $sum = fn (callable $value) => array_sum(array_map($value, $environments));

        return new WallPageData(
            kpis: new WallKpisData(
                environmentsUp: count(array_filter($environments, fn (EnvironmentData $environment) => in_array($environment->status, [EnvironmentStatus::Active, EnvironmentStatus::Degraded], true))),
                environmentsTotal: count($environments),
                openAnomalies: count($anomalies),
                pendingTotal: $sum(fn (EnvironmentData $environment) => $environment->pending),
                failedLast24HoursTotal: $sum(fn (EnvironmentData $environment) => $environment->failedLast24Hours),
            ),
            environments: $environments,
            anomalies: array_slice($anomalies, 0, 5),
            throughput: $this->monitoring->throughputSeries($team, null, SeriesRange::ThreeHours),
            jobsPerMinute: $sum(fn (EnvironmentData $environment) => $environment->jobsPerMinute),
            notifications: $this->monitoring->sentNotifications($team),
            applicationCount: count($this->monitoring->applications($team)),
        );
    }
}
