<?php

namespace App\Monitoring;

use App\Data\Monitoring\AlertData;
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
use App\Enums\AlertState;
use App\Enums\SeriesRange;
use App\Models\Team;
use Illuminate\Container\Attributes\Bind;

#[Bind(FakeMonitoringRepository::class)]
interface MonitoringRepository
{
    /** @return array<int, ApplicationData> */
    public function applications(Team $team): array;

    public function application(Team $team, string $applicationId): ?ApplicationData;

    /** @return array<int, EnvironmentData> */
    public function environments(Team $team): array;

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
     * Jobs per minute across the range; a null environment means the whole organization.
     *
     * @return array<int, int>
     */
    public function throughputSeries(Team $team, ?string $environmentId, SeriesRange $range): array;

    /** @return array<int, int> */
    public function maxWaitSeries(Team $team, string $environmentId, SeriesRange $range): array;

    /** @return array<int, AlertData> */
    public function alerts(Team $team, AlertState $state): array;

    /** @return array<int, SentNotificationData> */
    public function sentNotifications(Team $team): array;

    /** @return array<int, RuleScopeData> */
    public function ruleScopes(Team $team): array;

    /** @return array<int, AlertRuleData> */
    public function alertRules(Team $team, string $scope): array;

    public function notificationSettings(Team $team): NotificationSettingsData;
}
