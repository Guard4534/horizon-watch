<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\WallKpisData;
use App\Data\Pages\WallPageData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentType;
use App\Enums\SeriesRange;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Support\Facades\Gate;

class WallQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer): WallPageData
    {
        $environments = $this->monitoring->environments($team);
        usort($environments, EnvironmentData::compareBySeverityThenPending(...));

        $anomalies = $this->monitoring->openAlerts($team);
        $judged = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->isJudged()));
        $sum = fn (array $rows, callable $value) => array_sum(array_map($value, $rows));
        $failedRule = AlertRuleMetric::JobsFailedPerHour->value;
        $overFailedRate = array_values(array_filter(
            $judged,
            fn (EnvironmentData $environment) => $environment->failedLastHour > ($environment->thresholds[$failedRule]->value ?? INF),
        ));

        return new WallPageData(
            kpis: new WallKpisData(
                environmentsHealthy: count(array_filter($judged, fn (EnvironmentData $environment) => $environment->isHealthy())),
                environmentsJudged: count($judged),
                environmentsNeedingAttention: count(array_filter($judged, fn (EnvironmentData $environment) => $environment->needsAttention())),
                openAnomalies: count($anomalies),
                openAlertSeverity: AlertSeverity::worstOf(array_map(fn (AlertData $alert) => $alert->severity, $anomalies)),
                pendingTotal: $sum($judged, fn (EnvironmentData $environment) => $environment->pending),
                failedLastHour: $sum($judged, fn (EnvironmentData $environment) => $environment->failedLastHour),
                failedLastHourCapped: array_any($judged, fn (EnvironmentData $environment) => $environment->failedLastHourCapped),
                failedSeverity: AlertSeverity::worstOf(array_map(
                    fn (EnvironmentData $environment) => $environment->thresholds[$failedRule]->severity,
                    $overFailedRate,
                )),
                failedInWindow: $sum($judged, fn (EnvironmentData $environment) => $environment->failedInWindow),
                failedWindowMinutes: $this->commonFailedWindow($judged === [] ? $environments : $judged),
            ),
            environments: $environments,
            anomalies: array_slice($anomalies, 0, config()->integer('horizon-watch.pages.wall_anomalies')),
            throughput: $this->monitoring->throughputSeries($team, null, SeriesRange::ThreeHours),
            grid: $this->monitoring->seriesGrid(SeriesRange::ThreeHours),
            jobsPerMinute: $sum($judged, fn (EnvironmentData $environment) => $environment->jobsPerMinute),
            notifications: $this->monitoring->sentNotifications($team),
            applicationCount: count($this->monitoring->applications($team)),
            alertsReachNobody: $this->monitoring->notificationSummary($team)->reachesNobody,
            canManageAlertRules: Gate::forUser($viewer)->allows('manageAlertRules', $team),
            types: EnvironmentType::options(),
        );
    }

    /**
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
