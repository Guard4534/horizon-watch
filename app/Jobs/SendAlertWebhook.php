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
use App\Models\NotificationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Throwable;

class SendAlertWebhook extends DeliverAlert
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        int $teamId,
        ?string $alertId,
        SentNotificationKind $kind,
        public readonly array $payload,
        public readonly ?int $environmentCount = null,
    ) {
        parent::__construct($teamId, $alertId, $kind);
    }

    public function handle(WebhookClient $client): void
    {
        $setting = $this->recorded() ? null : $this->setting();

        if ($setting === null) {
            return;
        }

        $host = self::host((string) $setting->webhook_url);

        if ($this->alertId !== null && ! $this->alertStillDue($host)) {
            return;
        }

        try {
            $secret = (string) $setting->getAttribute('webhook_secret');
        } catch (DecryptException $exception) {
            $this->fail($exception);

            return;
        }

        try {
            $client->post(
                (string) $setting->webhook_url,
                $secret,
                WebhookPayload::stamped($this->payload, CarbonImmutable::now(), $this->deliveryId),
                $this->previousSecret($setting),
            );
        } catch (WebhookFailed $exception) {
            if ($exception->permanent()) {
                $this->fail($exception);

                return;
            }

            if ($exception->retryAfter !== null && $this->attempts() < $this->tries) {
                $this->release(min(
                    max($exception->retryAfter, $this->backoff[min($this->attempts(), count($this->backoff)) - 1] ?? 0),
                    config()->integer('horizon-watch.notifications.retry_after_max_seconds'),
                ));

                return;
            }

            throw $exception;
        }

        $this->log(DeliveryStatus::Sent, null, $host, $this->environmentCount);
    }

    protected function channel(): NotificationChannel
    {
        return NotificationChannel::Webhook;
    }

    protected function target(): ?string
    {
        $setting = $this->setting();

        return $setting === null ? null : self::host((string) $setting->webhook_url);
    }

    protected function errorOf(?Throwable $exception): DeliveryError
    {
        return match (true) {
            $exception instanceof WebhookFailed => $exception->reason,
            $exception instanceof DecryptException => DeliveryError::SecretUnreadable,
            default => DeliveryError::Unreachable,
        };
    }

    protected function httpStatusOf(?Throwable $exception): ?int
    {
        return $exception instanceof WebhookFailed ? $exception->status : null;
    }

    protected function loggedEnvironmentCount(): ?int
    {
        return $this->environmentCount;
    }

    private function alertStillDue(string $host): bool
    {
        $alert = Alert::query()->where('team_id', $this->teamId)->find($this->alertId);

        return $alert !== null && $this->stillDueTo($alert, $host);
    }

    private function setting(): ?NotificationSetting
    {
        $setting = NotificationSetting::query()->find($this->teamId);

        return $setting !== null && $setting->team()->exists() && filled($setting->webhook_url) && $setting->getRawOriginal('webhook_secret') !== null
            ? $setting
            : null;
    }

    private function previousSecret(NotificationSetting $setting): ?string
    {
        if ($setting->previous_webhook_secret_expires_at?->isFuture() !== true) {
            return null;
        }

        try {
            $secret = $setting->getAttribute('previous_webhook_secret');
        } catch (DecryptException) {
            return null;
        }

        return is_string($secret) ? $secret : null;
    }

    private static function host(string $url): string
    {
        return mb_substr(strtolower((string) parse_url($url, PHP_URL_HOST)), 0, 255);
    }
}
