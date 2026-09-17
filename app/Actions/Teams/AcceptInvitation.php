<?php

namespace App\Actions\Teams;

use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    public function __construct(private readonly ChangeMemberVisibility $changeVisibility) {}

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

            // Only for a membership this call created: accepting a second
            // invitation to an organization one already belongs to must not
            // silently rewrite the role and visibility an admin set there.
            //
            // The write goes through ChangeMemberVisibility because
            // environment_user is keyed by user_id, not by membership: a
            // sync() here would detach the manual grants this person holds
            // in every *other* organization.
            if ($membership->wasRecentlyCreated) {
                $this->changeVisibility->handle(
                    $team,
                    $user,
                    $locked->visibility,
                    $locked->environments()->pluck('environments.id')->all(),
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
