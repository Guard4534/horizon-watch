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
