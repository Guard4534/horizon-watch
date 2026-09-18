<?php

namespace App\Jobs;

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
    }

    public function handle(WebhookClient $client): void
    {
        $setting = $this->setting();

        if ($setting === null) {
            return;
        }

        try {
            $client->post(
                (string) $setting->webhook_url,
                (string) $setting->webhook_secret,
                WebhookPayload::stamped($this->payload, CarbonImmutable::now()),
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
        $setting = $this->setting();

        if ($setting !== null) {
            $this->log(
                DeliveryStatus::Failed,
                $exception instanceof WebhookFailed ? $exception->reason : DeliveryError::Unreachable,
                $setting,
            );
        }
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
        ]);
    }

    private static function host(string $url): string
    {
        return mb_substr(strtolower((string) parse_url($url, PHP_URL_HOST)), 0, 255);
    }
}
