<?php

namespace App\Actions\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferOwnership
{
    public function handle(Team $team, User $owner, User $target): void
    {
        DB::transaction(function () use ($team, $owner, $target) {
            $memberships = $team->memberships()
                ->whereIn('user_id', [$owner->id, $target->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            /** @var Membership|null $current */
            $current = $memberships->get($owner->id);
            /** @var Membership|null $next */
            $next = $memberships->get($target->id);

            abort_unless($current?->role === TeamRole::Owner, 403);
            abort_if($next === null || $owner->is($target), 404);

            $current->update(['role' => TeamRole::Admin]);

            $next->clearEnvironmentGrants();
            $next->update(['role' => TeamRole::Owner, 'visibility' => MemberVisibility::All]);
        });
    }
}
