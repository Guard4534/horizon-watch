<?php

namespace App\Actions\Alerts;

use App\Enums\MuteDuration;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MuteAlert
{
    public function handle(Alert $alert, User $by, MuteDuration $duration): void
    {
        if ($alert->resolved_at !== null) {
            throw ValidationException::withMessages(['alert' => __('This alert is already resolved.')]);
        }

        $minutes = $duration->minutes();

        $alert->forceFill([
            'muted_until' => $minutes === null ? null : now()->addMinutes($minutes),
            'muted_indefinitely' => $minutes === null,
            'muted_by' => $by->id,
        ])->save();
    }
}
