<?php

namespace App\Actions\Teams;

use App\Enums\MemberVisibility;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeMemberVisibility
{
    /**
     * @param  array<int, int>  $environmentIds
     */
    public function handle(Team $team, User $target, MemberVisibility $visibility, array $environmentIds = []): Membership
    {
        return DB::transaction(function () use ($team, $target, $visibility, $environmentIds) {
            /** @var Membership $membership */
            $membership = $team->memberships()->where('user_id', $target->id)->lockForUpdate()->firstOrFail();

            if ($membership->role->seesEveryEnvironment() && $visibility !== MemberVisibility::All) {
                throw ValidationException::withMessages([
                    'visibility' => __('Admins and the owner always see every environment.'),
                ]);
            }

            $membership->update(['visibility' => $visibility]);

            $membership->clearEnvironmentGrants();

            if ($visibility === MemberVisibility::Manual) {
                $rows = collect($environmentIds)
                    ->map(fn (int $id) => (int) $id)
                    ->intersect($team->environments()->pluck('environments.id'))
                    ->unique()
                    ->map(fn (int $id) => ['user_id' => $target->id, 'environment_id' => $id])
                    ->values()
                    ->all();

                if ($rows !== []) {
                    DB::table('environment_user')->insert($rows);
                }
            }

            return $membership;
        });
    }
}
