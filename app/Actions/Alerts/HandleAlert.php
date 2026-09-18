<?php

namespace App\Actions\Alerts;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class HandleAlert
{
    public function handle(Alert $alert, User $by): void
    {
        if ($alert->resolved_at !== null) {
            throw ValidationException::withMessages(['alert' => __('This alert is already resolved.')]);
        }

        Alert::query()
            ->whereKey($alert->id)
            ->whereNull('handled_at')
            ->update(['handled_at' => now(), 'handled_by' => $by->id]);
    }
}
