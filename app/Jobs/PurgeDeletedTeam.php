<?php

namespace App\Jobs;

use App\Actions\Teams\DeleteTeamApplications;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class PurgeDeletedTeam implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly int $teamId) {}

    public function handle(DeleteTeamApplications $deleteApplications): void
    {
        if (! $deleteApplications->handle($this->teamId, config()->float('horizon-watch.readings.purge_budget_seconds'))) {
            self::dispatch($this->teamId);
        }
    }
}
