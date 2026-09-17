<?php

namespace App\Actions\Teams;

use App\Enums\MemberVisibility;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeMemberVisibility
{
    /**
     * @param  array<int, int>  $environmentIds
     */
    public function handle(Team $team, User $target, MemberVisibility $visibility, array $environmentIds = []): Membership
    {
        /** @var Membership $membership */
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();

        return DB::transaction(function () use ($team, $target, $membership, $visibility, $environmentIds) {
            $membership->update(['visibility' => $visibility]);

            $teamEnvironmentIds = $team->environments()->pluck('environments.id');

            DB::table('environment_user')
                ->where('user_id', $target->id)
                ->whereIn('environment_id', $teamEnvironmentIds)
                ->delete();

            if ($visibility === MemberVisibility::Manual) {
                $rows = collect($environmentIds)
                    ->map(fn (int $id) => (int) $id)
                    ->intersect($teamEnvironmentIds)
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
