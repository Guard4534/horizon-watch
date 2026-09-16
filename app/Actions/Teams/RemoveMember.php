<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    /**
     * Drop a membership, together with the environment grants it carried.
     * Whether the actor may do this is the TeamPolicy's business
     * (removeMember refuses when the target is the owner).
     *
     * Deliberately asymmetric with ChangeMemberRole: the last admin
     * besides the owner may not demote *herself*, but she may remove
     * herself. Demotion is the accident — the row stays, the buttons
     * quietly go, and nobody notices the organization has no working admin
     * left. Removing yourself is an unmistakable act with an unmistakable
     * result (you lose the organization from your switcher), it is the
     * same thing "teams.leave" already allows without a guard, and the
     * owner can always invite you back. If that judgement is wrong, the
     * guard to add is the same one, on $team->memberships() minus the
     * target, right here.
     */
    public function handle(Team $team, User $target): void
    {
        DB::transaction(function () use ($team, $target) {
            DB::table('environment_user')
                ->where('user_id', $target->id)
                ->whereIn('environment_id', $team->environments()->pluck('environments.id'))
                ->delete();

            $team->memberships()->where('user_id', $target->id)->delete();

            // The person may be looking at this organization right now:
            // leave them somewhere they still belong. Their personal team
            // always exists in practice; fallbackTeam() covers the
            // imported-data case where it does not.
            if ($target->isCurrentTeam($team)) {
                $fallback = $target->personalTeam() ?? $target->fallbackTeam($team);

                if ($fallback) {
                    $target->switchTeam($fallback);
                }
            }
        });
    }
}
