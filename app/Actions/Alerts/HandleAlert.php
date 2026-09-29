<?php

namespace App\Actions\Alerts;

use App\Alerts\AlertClaim;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class HandleAlert
{
    public function handle(Alert $alert, User $by): void
    {
        AlertClaim::whileOpen(
            $alert,
            ['handled_at' => now(), 'handled_by' => $by->id],
            fn (Builder $claim) => $claim->whereNull('handled_at'),
        );
    }
}
