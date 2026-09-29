<?php

namespace App\Actions\Teams;

use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public function handle(Team $team, User $target): void
    {
        /** @var Membership $membership */
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();

        DB::transaction(function () use ($team, $target, $membership) {
            $membership->clearEnvironmentGrants();

            $membership->delete();

            if ($target->isCurrentTeam($team)) {
                $fallback = $target->fallbackTeam($team);

                if ($fallback) {
                    $target->switchTeam($fallback);
                } else {
                    $target->update(['current_team_id' => null]);
                }
            }
        });
    }
}
