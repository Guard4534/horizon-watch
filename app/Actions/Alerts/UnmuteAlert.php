<?php

namespace App\Actions\Alerts;

use App\Alerts\AlertClaim;
use App\Models\Alert;

class UnmuteAlert
{
    public function handle(Alert $alert): void
    {
        AlertClaim::whileOpen($alert, [
            'muted_until' => null,
            'muted_indefinitely' => false,
            'muted_by' => null,
        ]);
    }
}
