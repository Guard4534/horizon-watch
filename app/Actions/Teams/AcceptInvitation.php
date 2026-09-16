<?php

namespace App\Actions\Teams;

use App\Enums\MemberVisibility;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    /**
     * Create the membership with the invitation's role and visibility,
     * copy the chosen environments into environment_user, mark the
     * invitation accepted, and switch the user to the new team.
     *
     * Locks the invitation row for the duration of the transaction: two
     * clicks (double submit, a retried request) must not create two
     * memberships. firstOrCreate() alone isn't enough under concurrency —
     * the lock is what serialises the two attempts.
     */
    public function handle(TeamInvitation $invitation, User $user): Team
    {
        return DB::transaction(function () use ($invitation, $user) {
            $locked = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $team = $locked->team;

            /** @var Membership $membership */
            $membership = $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $locked->role, 'visibility' => $locked->visibility],
            );

            if ($locked->visibility === MemberVisibility::Manual) {
                $membership->visibleEnvironments()->sync(
                    $locked->environments()->pluck('environments.id'),
                );
            }

            if (! $locked->isAccepted()) {
                $locked->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
            }

            $user->switchTeam($team);

            return $team;
        });
    }
}
