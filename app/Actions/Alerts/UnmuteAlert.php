<?php

namespace App\Actions\Alerts;

use App\Models\Alert;
use Illuminate\Validation\ValidationException;

class UnmuteAlert
{
    public function handle(Alert $alert): void
    {
        if ($alert->resolved_at !== null) {
            throw ValidationException::withMessages(['alert' => __('This alert is already resolved.')]);
        }

        $alert->forceFill([
            'muted_until' => null,
            'muted_indefinitely' => false,
            'muted_by' => null,
        ])->save();
    }
}
