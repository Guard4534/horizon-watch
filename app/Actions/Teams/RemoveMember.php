<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public function handle(Team $team, User $target): void
    {
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();

        DB::transaction(function () use ($team, $target, $membership) {
            DB::table('environment_user')
                ->where('user_id', $target->id)
                ->whereIn('environment_id', $team->environments()->pluck('environments.id'))
                ->delete();

            $membership->delete();

            if ($target->isCurrentTeam($team)) {
                $fallback = $target->fallbackTeam($team);

                if ($fallback) {
                    $target->switchTeam($fallback);
                }
            }
        });
    }
}
