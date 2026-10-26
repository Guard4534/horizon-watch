<?php

namespace App\Actions\Settings;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class EndOtherSessions
{
    public function handle(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config()->get('session.connection'))
            ->table(config()->string('session.table'))
            ->where('user_id', $user->getKey())
            ->when($keepSessionId !== null, fn (Builder $query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
