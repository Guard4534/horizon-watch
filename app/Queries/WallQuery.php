<?php

namespace App\Queries;

use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\WallKpisData;
use App\Data\Pages\WallPageData;
use App\Enums\AlertRuleMetric;
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
        $threshold = AlertRuleMetric::JobsFailedPerHour->defaultThreshold();

        return new WallPageData(
            kpis: new WallKpisData(
                environmentsUp: count(array_filter($environments, fn (EnvironmentData $environment) => in_array($environment->status, [EnvironmentStatus::Active, EnvironmentStatus::Degraded], true))),
                environmentsActive: count(array_filter($environments, fn (EnvironmentData $environment) => $environment->status === EnvironmentStatus::Active)),
                environmentsTotal: count($environments),
                openAnomalies: count($anomalies),
                pendingTotal: $sum(fn (EnvironmentData $environment) => $environment->pending),
                failedTotal: $sum(fn (EnvironmentData $environment) => $environment->failedLast24Hours),
                failedWindowMinutes: $this->commonFailedWindow($environments),
                environmentsOverFailedRate: count(array_filter(
                    $environments,
                    fn (EnvironmentData $environment) => $environment->failedLast24Hours * 60 / max(1, $environment->failedWindowMinutes) > $threshold,
                )),
            ),
            environments: $environments,
            anomalies: array_slice($anomalies, 0, 5),
            throughput: $this->monitoring->throughputSeries($team, null, SeriesRange::ThreeHours),
            jobsPerMinute: $sum(fn (EnvironmentData $environment) => $environment->jobsPerMinute),
            notifications: $this->monitoring->sentNotifications($team),
            applicationCount: count($this->monitoring->applications($team)),
            failedPerHourThreshold: $threshold,
        );
    }

    /**
     * An environment never read carries the column default, not a window
     * Horizon stated: it only decides the label when nothing was read at
     * all, or one new environment would turn every label into "mixed".
     *
     * @param  array<int, EnvironmentData>  $environments
     */
    private function commonFailedWindow(array $environments): ?int
    {
        $read = array_filter($environments, fn (EnvironmentData $environment) => $environment->lastReadingAt !== null);
        $windows = array_unique(array_map(
            fn (EnvironmentData $environment) => $environment->failedWindowMinutes,
            $read === [] ? $environments : $read,
        ));

        return count($windows) === 1 ? reset($windows) : null;
    }
}
