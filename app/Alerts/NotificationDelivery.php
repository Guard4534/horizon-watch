<?php

namespace App\Alerts;

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;

final readonly class NotificationDelivery implements AlertDelivery
{
    public function __construct(private Recipients $recipients) {}

    public function sendTest(Team $team, NotificationChannel $channel, User $requestedBy): int
    {
        return match ($channel) {
            NotificationChannel::Mail => $this->queueEmails($team, null, SentNotificationKind::Test, $this->recipients->forTest($team, $requestedBy)),
            NotificationChannel::Webhook => $this->queueWebhook($team, null, SentNotificationKind::Test, WebhookPayload::forTest($team)) ? 1 : 0,
        };
    }

    /**
     * @param  list<array{email: string, locale: string, user: ?User}>  $recipients
     * @param  array{repeated?: bool, alertIds?: list<string>, environmentCount?: int}  $payload
     */
    public function queueEmails(Team $team, ?string $alertId, SentNotificationKind $kind, array $recipients, array $payload = []): int
    {
        foreach ($recipients as $recipient) {
            SendAlertEmail::dispatch(
                $team->id,
                $alertId,
                $kind,
                $recipient['user']?->id,
                $recipient['user'] === null ? Recipients::addressKey($recipient['email']) : null,
                $recipient['locale'],
                $payload,
            );
        }

        return count($recipients);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function queueWebhook(Team $team, ?string $alertId, SentNotificationKind $kind, array $payload, ?int $environmentCount = null): bool
    {
        if (! $this->hasWebhook($team)) {
            return false;
        }

        $this->dispatchWebhook($team, $alertId, $kind, $payload, $environmentCount);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchWebhook(Team $team, ?string $alertId, SentNotificationKind $kind, array $payload, ?int $environmentCount = null): void
    {
        SendAlertWebhook::dispatch($team->id, $alertId, $kind, (string) $payload['event'], $payload, $environmentCount);
    }

    public function hasWebhook(Team $team): bool
    {
        $setting = NotificationSetting::query()->find($team->id);

        return $setting !== null && filled($setting->webhook_url) && filled($setting->webhook_secret);
    }
}
