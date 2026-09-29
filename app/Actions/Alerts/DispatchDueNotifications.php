<?php

namespace App\Actions\Alerts;

use App\Alerts\DeliveryPolicy;
use App\Support\SafeReport;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

class DispatchDueNotifications
{
    public function repeats(): void
    {
        $policy = $this->policy();
        $now = CarbonImmutable::now();

        $this->guarded('repetitions', fn () => $policy->repeatDue($now));
        $this->guarded('resolutions', fn () => $policy->resolutionsDue($now));
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

    /**
     * @param  Closure(): void  $step
     */
    private function guarded(string $name, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            SafeReport::of('Alert '.$name, $exception);
        }
    }
}
