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
use App\Models\NotificationSetting;
use Carbon\CarbonImmutable;
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
        public readonly string $event,
        public readonly array $payload,
        public readonly ?int $environmentCount = null,
    ) {
        parent::__construct($teamId, $alertId, $kind);
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

        $this->log(DeliveryStatus::Sent, null, self::host((string) $setting->webhook_url), $this->environmentCount);
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
        return $exception instanceof WebhookFailed ? $exception->reason : DeliveryError::Unreachable;
    }

    protected function loggedEnvironmentCount(): ?int
    {
        return $this->environmentCount;
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

    private function setting(): ?NotificationSetting
    {
        $setting = NotificationSetting::query()->find($this->teamId);

        return $setting !== null && $setting->team()->exists() && filled($setting->webhook_url) && filled($setting->webhook_secret)
            ? $setting
            : null;
    }

    private static function host(string $url): string
    {
        return mb_substr(strtolower((string) parse_url($url, PHP_URL_HOST)), 0, 255);
    }
}
