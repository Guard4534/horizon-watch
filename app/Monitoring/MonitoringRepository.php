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

#[Bind(ConfiguredMonitoringRepository::class)]
interface MonitoringRepository
{
    /**
     * The applications the viewer is watching: those with at least one
     * environment visible to them, plus — for a member who may manage
     * applications — the ones with no environment at all.
     *
     * @return array<int, ApplicationData>
     */
    public function applications(Team $team): array;

    /**
     * The environments the viewer is watching.
     *
     * @return array<int, EnvironmentData>
     */
    public function environments(Team $team): array;

    /**
     * The Applications pages' lists. Visibility is not a permission (phase 2
     * spec): a member who may manage applications configures every
     * environment of the organization from there, including the ones their
     * own visibility hides from the wall. For everybody else these answer
     * exactly like applications() and environments().
     *
     * @return array<int, ApplicationData>
     */
    public function configurableApplications(Team $team): array;

    public function configurableApplication(Team $team, string $applicationId): ?ApplicationData;

    /** @return array<int, EnvironmentData> */
    public function configurableEnvironments(Team $team): array;

    /**
     * One environment by slug, for its detail page: the watched view, so a
     * hidden environment answers null (and the page 404s) whatever the
     * viewer may manage.
     */
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
