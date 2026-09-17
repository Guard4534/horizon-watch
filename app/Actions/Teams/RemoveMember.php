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
        // firstOrFail(), so that "remove somebody who is not a member" is a
        // 404 rather than a 302 with "Member removed." on it. The check
        // belongs here and not in a controller: three routes reach this
        // action (the Members view, the starter kit's settings page, and
        // leaving an organization), and a silent no-op on the second of
        // them was a user-existence oracle over the whole installation.
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();

        DB::transaction(function () use ($team, $target, $membership) {
            DB::table('environment_user')
                ->where('user_id', $target->id)
                ->whereIn('environment_id', $team->environments()->pluck('environments.id'))
                ->delete();

            $membership->delete();

            // The person may be looking at this organization right now:
            // leave them somewhere they still belong. The alphabetically
            // first team they have left, which is their personal team when
            // that is all that remains — the rule "teams.leave" has always
            // used. Null only for a membership imported without a personal
            // team.
            if ($target->isCurrentTeam($team)) {
                $fallback = $target->fallbackTeam($team);

                if ($fallback) {
                    $target->switchTeam($fallback);
                }
            }
        });
    }
}
