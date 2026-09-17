<?php

namespace App\Alerts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class AlertResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $alertId) {}
}
