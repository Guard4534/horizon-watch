<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

class Grants
{
    public static function give(int $userId, int ...$environmentIds): void
    {
        DB::table('environment_user')->insert(array_map(
            fn (int $environmentId) => ['user_id' => $userId, 'environment_id' => $environmentId],
            $environmentIds,
        ));
    }

    /**
     * @return array<int, int>
     */
    public static function of(int $userId, int $teamId): array
    {
        return DB::table('environment_user')
            ->join('environments', 'environments.id', '=', 'environment_user.environment_id')
            ->where('environment_user.user_id', $userId)
            ->where('environments.team_id', $teamId)
            ->orderBy('environments.id')
            ->pluck('environments.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
