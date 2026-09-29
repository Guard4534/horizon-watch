<?php

namespace App\Actions\Alerts;

use App\Alerts\AlertClaim;
use App\Enums\MuteDuration;
use App\Models\Alert;
use App\Models\User;

class MuteAlert
{
    public function handle(Alert $alert, User $by, MuteDuration $duration): void
    {
        $minutes = $duration->minutes();

        AlertClaim::whileOpen($alert, [
            'muted_until' => $minutes === null ? null : now()->addMinutes($minutes),
            'muted_indefinitely' => $minutes === null,
            'muted_by' => $by->id,
        ]);
    }
}
