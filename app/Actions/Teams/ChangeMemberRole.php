<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    /**
     * Give a member another role. Whether the actor may touch this member
     * at all is the TeamPolicy's business (updateMember also refuses when
     * the target is the owner): this action only enforces the one rule
     * that is about the organization's state rather than about permissions.
     */
    public function handle(Team $team, User $actor, User $target, TeamRole $role): Membership
    {
        /** @var Membership $membership */
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();

        $this->refuseLeavingNoAdmin($team, $actor, $target, $membership->role, $role);

        $membership->update(['role' => $role]);

        return $membership;
    }

    /**
     * An admin may not demote themselves if that would leave the
     * organization with no admin besides the owner. The owner keeps every
     * admin permission, so nothing would actually become impossible — but
     * the organization would be one person away from having nobody to
     * share the work with, and the person doing it would not notice until
     * the buttons were gone. Somebody else must be promoted first.
     *
     * Only self-demotion is guarded: demoting *another* admin is a
     * deliberate decision by someone who keeps their own admin rights.
     */
    private function refuseLeavingNoAdmin(
        Team $team,
        User $actor,
        User $target,
        TeamRole $current,
        TeamRole $next,
    ): void {
        if (! $actor->is($target) || $current !== TeamRole::Admin || $next === TeamRole::Admin) {
            return;
        }

        $otherAdmins = $team->memberships()
            ->where('role', TeamRole::Admin->value)
            ->where('user_id', '!=', $target->id)
            ->exists();

        if ($otherAdmins) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => __('You are the only admin besides the owner: promote someone else before giving up your own admin role.'),
        ]);
    }
}
