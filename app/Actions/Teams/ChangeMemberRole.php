<?php

namespace App\Actions\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberRole
{
    public function handle(Team $team, User $actor, User $target, TeamRole $role): Membership
    {
        return DB::transaction(function () use ($team, $actor, $target, $role) {
            /** @var Membership $membership */
            $membership = $team->memberships()->where('user_id', $target->id)->lockForUpdate()->firstOrFail();

            $this->refuseLeavingNoAdmin($team, $actor, $target, $membership->role, $role);

            $membership->update(['role' => $role]);

            if ($role->seesEveryEnvironment() && $membership->visibility !== MemberVisibility::All) {
                $membership->clearEnvironmentGrants();
                $membership->update(['visibility' => MemberVisibility::All]);
            }

            return $membership;
        });
    }

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
