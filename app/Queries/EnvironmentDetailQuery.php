<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Pages\EnvironmentDetailPageData;
use App\Enums\AlertState;
use App\Enums\SeriesRange;
use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Contracts\Auth\Guard;

class EnvironmentDetailQuery
{
    public function __construct(
        private MonitoringRepository $monitoring,
        private Guard $auth,
    ) {}

    public function handle(Team $team, string $environmentId, SeriesRange $range): EnvironmentDetailPageData
    {
        $environment = $this->monitoring->environment($team, $environmentId) ?? abort(404);

        // Never the environment's own scope: its overrides are invented
        // until phase 4, and the evaluator ignores them.
        $rules = $this->monitoring->alertRules($team, 'organization');

        $openAlerts = array_filter(
            $this->monitoring->alerts($team, AlertState::Open),
            fn (AlertData $alert) => $alert->environmentId === $environment->id,
        );

        return new EnvironmentDetailPageData(
            environment: $environment,
            openAlert: array_values($openAlerts)[0] ?? null,
            nodes: $this->monitoring->nodes($team, $environment->id),
            queues: $this->monitoring->queues($team, $environment->id),
            failedJobs: $this->monitoring->failedJobs($team, $environment->id),
            longRunningJobs: $this->monitoring->longRunningJobs($team, $environment->id),
            range: $range,
            throughput: $this->monitoring->throughputSeries($team, $environment->id, $range),
            maxWait: $this->monitoring->maxWaitSeries($team, $environment->id, $range),
            rules: $rules,
            canTestConnection: $this->canTestConnection($team),
            thresholds: array_combine(
                array_map(fn (AlertRuleData $rule) => $rule->metric->value, $rules),
                array_map(fn (AlertRuleData $rule) => $rule->threshold, $rules),
            ),
        );
    }

    /**
     * EnvironmentPolicy::testConnection() is a team permission and the
     * environment was just found in this team, so the answer is the same
     * without loading the model the repository keeps to itself. Trap: this
     * mirrors the policy instead of calling it — if the policy ever looks
     * at the environment, change this too.
     */
    private function canTestConnection(Team $team): bool
    {
        $user = $this->auth->user();

        return $user instanceof User && $user->hasTeamPermission($team, TeamPermission::TestConnection);
    }
}
