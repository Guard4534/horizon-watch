<?php

namespace App\Listeners\Alerts;

use App\Alerts\Events\AlertOpened;

class SendOnOpen extends SendOnAlert
{
    public function handle(AlertOpened $event): void
    {
        $this->deliver($event->alertId, $this->policy->onOpened(...));
    }
}
