<?php

namespace App\Listeners\Alerts;

use App\Alerts\DeliveryPolicy;
use App\Models\Alert;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;

abstract class SendOnAlert implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(protected readonly DeliveryPolicy $policy) {}

    /**
     * @param  Closure(Alert): void  $announce
     */
    protected function deliver(string $alertId, Closure $announce): void
    {
        $alert = Alert::query()->with(['team', 'environment.state'])->find($alertId);

        if ($alert !== null) {
            $announce($alert);
        }
    }
}
