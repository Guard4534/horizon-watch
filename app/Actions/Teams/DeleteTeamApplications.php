<?php

namespace App\Actions\Teams;

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Support\ChunkedDelete;

class DeleteTeamApplications
{
    public function handle(int $teamId, ?float $budgetSeconds = null): bool
    {
        if (! Team::onlyTrashed()->whereKey($teamId)->exists()) {
            return true;
        }

        $snapshots = EnvironmentSnapshot::query()
            ->whereIn('environment_id', Environment::query()->where('team_id', $teamId)->select('id'));

        ChunkedDelete::run($snapshots, config()->integer('horizon-watch.readings.prune_chunk'), $budgetSeconds);

        if ((clone $snapshots)->exists()) {
            return false;
        }

        Application::query()->where('team_id', $teamId)->delete();

        return true;
    }
}
