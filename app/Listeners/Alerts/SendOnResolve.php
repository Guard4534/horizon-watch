<?php

namespace App\Listeners\Alerts;

use App\Alerts\DeliveryPolicy;
use App\Alerts\Events\AlertResolved;
use App\Models\Alert;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOnResolve implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(private readonly DeliveryPolicy $policy) {}

    public function handle(AlertResolved $event): void
    {
        $alert = Alert::query()->with(['team', 'environment.state'])->find($event->alertId);

        if ($alert !== null) {
            $this->policy->onResolved($alert);
        }
    }
}
