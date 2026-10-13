<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;

class DeclineInvitation
{
    public function handle(TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $locked = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($locked->isPending(), 410);

            $locked->delete();
        });
    }
}
