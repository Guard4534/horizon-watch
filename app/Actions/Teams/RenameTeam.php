<?php

namespace App\Actions\Teams;

use App\Models\Team;
use Illuminate\Support\Facades\DB;

class RenameTeam
{
    public function handle(Team $team, string $name): Team
    {
        return DB::transaction(function () use ($team, $name) {
            $locked = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            $locked->update(['name' => $name]);

            return $locked;
        });
    }
}
