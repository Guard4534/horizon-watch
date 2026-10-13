<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;

class RevokeInvitation
{
    public function handle(TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $locked = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($locked->isPending(), 409);

            $locked->update(['revoked_at' => now()]);
        });
    }
}
