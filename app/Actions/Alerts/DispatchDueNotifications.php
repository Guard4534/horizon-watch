<?php

namespace App\Actions\Alerts;

use App\Alerts\DeliveryPolicy;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
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
            report(new RuntimeException(sprintf(
                'Alert %s threw %s at %s:%d.',
                $name,
                $exception::class,
                $exception->getFile(),
                $exception->getLine(),
            )));
        }
    }
}
