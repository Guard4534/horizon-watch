<?php

namespace App\Jobs;

use App\Alerts\Recipients;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\AlertNotification as DeliveryLog;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Alerts\AlertNotification;
use App\Notifications\Alerts\ResolvedNotification;
use App\Notifications\Alerts\TestNotification;
use App\Notifications\Alerts\WarningDigestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendAlertEmail implements ShouldQueue
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
     * @param  array{repeated?: bool, alertIds?: list<string>, environmentCount?: int}  $payload
     */
    public function __construct(
        public readonly int $teamId,
        public readonly ?string $alertId,
        public readonly SentNotificationKind $kind,
        public readonly ?int $userId,
        public readonly ?string $addressKey,
        public readonly string $locale,
        public readonly array $payload = [],
    ) {
        $this->tries = config()->integer('horizon-watch.notifications.delivery_tries');
        $this->backoff = array_values(array_map(intval(...), config()->array('horizon-watch.notifications.delivery_backoff_seconds')));
        $this->timeout = config()->integer('horizon-watch.notifications.delivery_timeout_seconds');
        $this->deliveryId = (string) Str::uuid();
    }

    public function handle(Recipients $recipients): void
    {
        if ($this->recorded()) {
            return;
        }

        $team = Team::query()->find($this->teamId);
        $email = $team === null ? null : $this->address($team, $recipients);
        $notification = $team === null || $email === null ? null : $this->notification($team);

        if ($email === null || $notification === null) {
            return;
        }

        try {
            DB::transaction(function () use ($email, $notification): void {
                $this->log(DeliveryStatus::Sent, null, $email);

                Notifications::route('mail', $email)->notifyNow($notification->locale($this->locale));
            });
        } catch (Throwable) {
            throw new RuntimeException(DeliveryError::Mail->value);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $team = $this->recorded() ? null : Team::query()->find($this->teamId);
            $email = $team === null ? null : $this->address($team, app(Recipients::class));

            if ($email !== null) {
                $this->log(DeliveryStatus::Failed, DeliveryError::Mail, $email);
            }
        } catch (Throwable $failure) {
            report(new RuntimeException(sprintf(
                'Recording a failed alert email threw %s at %s:%d.',
                $failure::class,
                $failure->getFile(),
                $failure->getLine(),
            )));
        }
    }

    private function recorded(): bool
    {
        return DeliveryLog::query()->where('delivery_id', $this->deliveryId)->exists();
    }

    private function address(Team $team, Recipients $recipients): ?string
    {
        if ($this->userId !== null) {
            $user = User::query()->find($this->userId);

            return $user !== null && ($this->kind === SentNotificationKind::Test || ($user->alert_emails && $user->belongsToTeam($team)))
                ? $user->email
                : null;
        }

        return $this->addressKey === null ? null : $recipients->extraAddress($team, $this->addressKey);
    }

    private function notification(Team $team): ?Notification
    {
        if ($this->kind === SentNotificationKind::Test) {
            return new TestNotification($team);
        }

        if ($this->kind === SentNotificationKind::WarningDigest) {
            $alerts = Alert::query()
                ->where('team_id', $team->id)
                ->whereIn('id', $this->payload['alertIds'] ?? [])
                ->with(['team', 'environment'])
                ->orderBy('opened_at')
                ->get();

            return $alerts->isEmpty() ? null : new WarningDigestNotification($team, $alerts->toBase());
        }

        $alert = $this->alertId === null
            ? null
            : Alert::query()->where('team_id', $team->id)->with(['team', 'environment.state'])->find($this->alertId);

        return match (true) {
            $alert === null => null,
            $this->kind === SentNotificationKind::Resolved => new ResolvedNotification($alert),
            default => new AlertNotification($alert, repeated: (bool) ($this->payload['repeated'] ?? false)),
        };
    }

    private function log(DeliveryStatus $status, ?DeliveryError $error, string $email): void
    {
        DeliveryLog::query()->create([
            'team_id' => $this->teamId,
            'alert_id' => $this->alertId !== null && Alert::query()->whereKey($this->alertId)->exists() ? $this->alertId : null,
            'kind' => $this->kind,
            'channel' => NotificationChannel::Mail,
            'target' => $email,
            'status' => $status,
            'error' => $error?->value,
            'sent_at' => Date::now(),
            'environment_count' => $this->kind === SentNotificationKind::WarningDigest ? ($this->payload['environmentCount'] ?? null) : null,
            'delivery_id' => $this->deliveryId,
        ]);
    }
}
