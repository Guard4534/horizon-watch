<?php

namespace App\Jobs;

use App\Alerts\DeliveryPolicy;
use App\Alerts\Payloads\WebhookPayload;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Externals\Webhook\WebhookClient;
use App\Externals\Webhook\WebhookFailed;
use App\Models\Alert;
use App\Models\AlertNotification as DeliveryLog;
use App\Models\NotificationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendAlertWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /**
     * @var list<int>
     */
    public array $backoff;

    public int $timeout;

    public readonly string $deliveryId;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $teamId,
        public readonly ?string $alertId,
        public readonly SentNotificationKind $kind,
        public readonly string $event,
        public readonly array $payload,
        public readonly ?int $environmentCount = null,
    ) {
        $this->tries = config()->integer('horizon-watch.notifications.delivery_tries');
        $this->backoff = array_values(array_map(intval(...), config()->array('horizon-watch.notifications.delivery_backoff_seconds')));
        $this->timeout = config()->integer('horizon-watch.notifications.delivery_timeout_seconds');
        $this->deliveryId = (string) Str::uuid();
    }

    public function handle(WebhookClient $client): void
    {
        $setting = $this->recorded() || ! $this->stillDue() ? null : $this->setting();

        if ($setting === null) {
            return;
        }

        try {
            $client->post(
                (string) $setting->webhook_url,
                (string) $setting->webhook_secret,
                WebhookPayload::stamped($this->payload, CarbonImmutable::now(), $this->deliveryId),
            );
        } catch (WebhookFailed $exception) {
            if ($exception->reason === DeliveryError::Blocked) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        $this->log(DeliveryStatus::Sent, null, $setting);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $setting = $this->recorded() ? null : $this->setting();

            if ($setting !== null) {
                $this->log(
                    DeliveryStatus::Failed,
                    $exception instanceof WebhookFailed ? $exception->reason : DeliveryError::Unreachable,
                    $setting,
                );
            }
        } catch (Throwable $failure) {
            report(new RuntimeException(sprintf(
                'Recording a failed alert webhook threw %s at %s:%d.',
                $failure::class,
                $failure->getFile(),
                $failure->getLine(),
            )));
        }
    }

    private function stillDue(): bool
    {
        if ($this->alertId === null) {
            return true;
        }

        $alert = Alert::query()->where('team_id', $this->teamId)->find($this->alertId);

        return $alert !== null && DeliveryPolicy::stillDue(
            $alert,
            $this->event === WebhookPayload::RESOLVED,
            $this->event === WebhookPayload::REPEATED,
            CarbonImmutable::now(),
        );
    }

    private function recorded(): bool
    {
        return DeliveryLog::query()->where('delivery_id', $this->deliveryId)->exists();
    }

    private function setting(): ?NotificationSetting
    {
        $setting = NotificationSetting::query()->find($this->teamId);

        return $setting !== null && $setting->team()->exists() && filled($setting->webhook_url) && filled($setting->webhook_secret)
            ? $setting
            : null;
    }

    private function log(DeliveryStatus $status, ?DeliveryError $error, NotificationSetting $setting): void
    {
        DeliveryLog::query()->create([
            'team_id' => $this->teamId,
            'alert_id' => $this->alertId !== null && Alert::query()->whereKey($this->alertId)->exists() ? $this->alertId : null,
            'kind' => $this->kind,
            'channel' => NotificationChannel::Webhook,
            'target' => self::host((string) $setting->webhook_url),
            'status' => $status,
            'error' => $error?->value,
            'sent_at' => Date::now(),
            'environment_count' => $this->environmentCount,
            'delivery_id' => $this->deliveryId,
        ]);
    }

    private static function host(string $url): string
    {
        return mb_substr(strtolower((string) parse_url($url, PHP_URL_HOST)), 0, 255);
    }
}
