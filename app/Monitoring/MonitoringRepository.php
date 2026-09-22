<?php

namespace App\Monitoring;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertPageData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\NotificationSettingsData;
use App\Data\Monitoring\QueueData;
use App\Data\Monitoring\RuleScopeData;
use App\Data\Monitoring\SentNotificationData;
use App\Data\Monitoring\SeriesGridData;
use App\Data\Pages\AlertCountsData;
use App\Enums\AlertState;
use App\Enums\SeriesRange;
use App\Models\Team;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;

#[Bind(ConfiguredMonitoringRepository::class)]
#[Scoped]
interface MonitoringRepository
{
    /**
     * @return array<int, ApplicationData>
     */
    public function applications(Team $team): array;

    /**
     * @return array<int, EnvironmentData>
     */
    public function environments(Team $team): array;

    /**
     * @return array<int, ApplicationData>
     */
    public function configurableApplications(Team $team): array;

    public function configurableApplication(Team $team, string $applicationId): ?ApplicationData;

    /** @return array<int, EnvironmentData> */
    public function configurableEnvironments(Team $team): array;

    public function environment(Team $team, string $environmentId): ?EnvironmentData;

    /** @return array<int, NodeData> */
    public function nodes(Team $team, string $environmentId): array;

    /** @return array<int, QueueData> */
    public function queues(Team $team, string $environmentId): array;

    /** @return array<int, FailedJobData> */
    public function failedJobs(Team $team, string $environmentId): array;

    /** @return array<int, LongRunningJobData> */
    public function longRunningJobs(Team $team, string $environmentId): array;

    /**
     * @return array<int, int>
     */
    public function throughputSeries(Team $team, ?string $environmentId, SeriesRange $range): array;

    /** @return array<int, int> */
    public function maxWaitSeries(Team $team, string $environmentId, SeriesRange $range): array;

    public function seriesGrid(SeriesRange $range): SeriesGridData;

    public function alerts(Team $team, AlertState $state, ?string $application = null, int $page = 1): AlertPageData;

    /** @return array<int, AlertData> */
    public function openAlerts(Team $team): array;

    /** @return array<int, AlertData> */
    public function latestResolvedAlerts(Team $team, string $application, int $limit): array;

    public function alertCounts(Team $team): AlertCountsData;

    /** @return array<int, SentNotificationData> */
    public function sentNotifications(Team $team): array;

    /** @return array<int, RuleScopeData> */
    public function ruleScopes(Team $team): array;

    /** @return array<int, AlertRuleData> */
    public function alertRules(Team $team, string $scope): array;

    public function notificationSettings(Team $team): NotificationSettingsData;
}
