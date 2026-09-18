<?php

namespace App\Actions\Alerts;

use App\Alerts\DeliveryPolicy;
use Carbon\CarbonImmutable;

class DispatchDueNotifications
{
    public function repeats(): void
    {
        $policy = $this->policy();
        $now = CarbonImmutable::now();

        $policy->repeatDue($now);
        $policy->resolutionsDue($now);
    }

    public function digests(): void
    {
        $this->policy()->digestDue(CarbonImmutable::now());
    }

    private function policy(): DeliveryPolicy
    {
        app()->forgetScopedInstances();

        return app(DeliveryPolicy::class);
    }
}
