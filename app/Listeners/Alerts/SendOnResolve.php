<?php

namespace App\Listeners\Alerts;

use App\Alerts\Events\AlertResolved;

class SendOnResolve extends SendOnAlert
{
    public function handle(AlertResolved $event): void
    {
        $this->deliver($event->alertId, $this->policy->onResolved(...));
    }
}
