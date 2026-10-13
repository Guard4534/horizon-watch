<?php

namespace App\Queries;

use App\Data\Pages\EnvironmentDetailPageData;
use App\Data\Pages\EnvironmentSeriesData;
use App\Enums\SeriesRange;
use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Support\Str;

class EnvironmentDetailQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer, string $environmentId): EnvironmentDetailPageData
    {
        $environment = $this->monitoring->environment($team, $environmentId) ?? abort(404);

        return new EnvironmentDetailPageData(
            environment: $environment,
            openAlert: $this->monitoring->openAlertIn($team, $environment->id),
            nodes: $this->monitoring->nodes($team, $environment->id),
            queues: $this->monitoring->queues($team, $environment->id),
            failedJobs: $this->monitoring->failedJobs($team, $environment->id),
            longRunningJobs: $this->monitoring->longRunningJobs($team, $environment->id),
            rules: $this->monitoring->alertRules($team, Str::lower($environment->name)),
            canTestConnection: $viewer->hasTeamPermission($team, TeamPermission::TestConnection),
        );
    }

    public function series(Team $team, string $environmentId, SeriesRange $range): EnvironmentSeriesData
    {
        $environment = $this->monitoring->environment($team, $environmentId) ?? abort(404);

        return new EnvironmentSeriesData(
            range: $range,
            throughput: $this->monitoring->throughputSeries($team, $environment->id, $range),
            maxWait: $this->monitoring->maxWaitSeries($team, $environment->id, $range),
            grid: $this->monitoring->seriesGrid($range),
        );
    }
}
