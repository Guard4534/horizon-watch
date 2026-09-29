<?php

namespace App\Jobs;

use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\AlertNotification as DeliveryLog;
use App\Support\SafeReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

abstract class DeliverAlert implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /**
     * @var list<int>
     */
    public array $backoff;

    public int $timeout;

    public readonly string $deliveryId;

    public function __construct(
        public readonly int $teamId,
        public readonly ?string $alertId,
        public readonly SentNotificationKind $kind,
    ) {
        $this->tries = config()->integer('horizon-watch.notifications.delivery_tries');
        $this->backoff = array_values(array_map(intval(...), config()->array('horizon-watch.notifications.delivery_backoff_seconds')));
        $this->timeout = config()->integer('horizon-watch.notifications.delivery_timeout_seconds');
        $this->deliveryId = (string) Str::uuid();
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $target = $this->recorded() ? null : $this->target();

            if ($target !== null) {
                $this->log(DeliveryStatus::Failed, $this->errorOf($exception), $target, $this->loggedEnvironmentCount());
            }
        } catch (Throwable $failure) {
            SafeReport::of('Recording a failed alert '.$this->channel()->value, $failure);
        }
    }

    abstract protected function channel(): NotificationChannel;

    abstract protected function target(): ?string;

    abstract protected function errorOf(?Throwable $exception): DeliveryError;

    protected function loggedEnvironmentCount(): ?int
    {
        return null;
    }

    protected function recorded(): bool
    {
        return DeliveryLog::query()->where('delivery_id', $this->deliveryId)->exists();
    }

    protected function log(DeliveryStatus $status, ?DeliveryError $error, string $target, ?int $environmentCount = null): void
    {
        DeliveryLog::query()->create([
            'team_id' => $this->teamId,
            'alert_id' => $this->alertId !== null && Alert::query()->whereKey($this->alertId)->exists() ? $this->alertId : null,
            'kind' => $this->kind,
            'channel' => $this->channel(),
            'target' => $target,
            'status' => $status,
            'error' => $error,
            'sent_at' => Date::now(),
            'environment_count' => $environmentCount,
            'delivery_id' => $this->deliveryId,
        ]);
    }
}
