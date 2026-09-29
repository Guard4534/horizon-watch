<?php

namespace App\Jobs;

use App\Alerts\DeliveryPolicy;
use App\Alerts\Recipients;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use App\Notifications\Alerts\AlertNotification;
use App\Notifications\Alerts\ResolvedNotification;
use App\Notifications\Alerts\TestNotification;
use App\Notifications\Alerts\WarningDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifications;
use RuntimeException;
use Throwable;

class SendAlertEmail extends DeliverAlert
{
    /**
     * @param  array{alertIds?: list<string>, environmentCount?: int}  $payload
     */
    public function __construct(
        int $teamId,
        ?string $alertId,
        SentNotificationKind $kind,
        public readonly ?int $userId,
        public readonly ?string $addressKey,
        public readonly string $locale,
        public readonly array $payload = [],
    ) {
        parent::__construct($teamId, $alertId, $kind);
    }

    public function handle(Recipients $recipients, VisibleEnvironments $visible): void
    {
        if ($this->recorded()) {
            return;
        }

        $team = Team::query()->find($this->teamId);
        $email = $team === null ? null : $this->address($team, $recipients);
        $notification = $team === null || $email === null ? null : $this->notification($team, $visible);

        if ($email === null || $notification === null) {
            return;
        }

        try {
            DB::transaction(function () use ($email, $notification): void {
                $this->log(DeliveryStatus::Sent, null, $email, $this->countOf($notification));

                Notifications::route('mail', $email)->notifyNow($notification->locale($this->locale));
            });
        } catch (Throwable) {
            throw new RuntimeException(DeliveryError::Mail->value);
        }
    }

    protected function channel(): NotificationChannel
    {
        return NotificationChannel::Mail;
    }

    protected function target(): ?string
    {
        $team = Team::query()->find($this->teamId);

        return $team === null ? null : $this->address($team, app(Recipients::class));
    }

    protected function errorOf(?Throwable $exception): DeliveryError
    {
        return DeliveryError::Mail;
    }

    protected function loggedEnvironmentCount(): ?int
    {
        return $this->kind === SentNotificationKind::WarningDigest ? ($this->payload['environmentCount'] ?? null) : null;
    }

    private function countOf(Notification $notification): ?int
    {
        return $notification instanceof WarningDigestNotification ? $notification->environmentCount() : null;
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

    private function notification(Team $team, VisibleEnvironments $visible): ?Notification
    {
        if ($this->kind === SentNotificationKind::Test) {
            return new TestNotification($team);
        }

        $member = $this->userId === null ? null : User::query()->find($this->userId);

        if ($this->kind === SentNotificationKind::WarningDigest) {
            $alerts = Alert::query()
                ->where('team_id', $team->id)
                ->whereIn('id', $this->payload['alertIds'] ?? [])
                ->with(['team', 'environment'])
                ->orderBy('opened_at')
                ->get();

            if ($member !== null) {
                $membership = $member->teamMemberships()->where('team_id', $team->id)->first();
                $ids = $membership === null ? [] : $visible->idsFor($team, $membership);
                $alerts = $alerts->filter(fn (Alert $alert) => $ids === null || in_array($alert->environment_id, $ids, true));
            }

            return $alerts->isEmpty() ? null : new WarningDigestNotification($team, $alerts->values()->toBase());
        }

        $alert = $this->alertId === null
            ? null
            : Alert::query()->where('team_id', $team->id)->with(['team', 'environment.state'])->find($this->alertId);

        return match (true) {
            $alert === null,
            ! DeliveryPolicy::stillDue($alert, $this->kind === SentNotificationKind::Resolved, $this->repeated(), CarbonImmutable::now()),
            $member !== null && ! $visible->sees($team, $member, $alert->environment_id) => null,
            $this->kind === SentNotificationKind::Resolved => new ResolvedNotification($alert),
            default => new AlertNotification($alert, repeated: $this->repeated()),
        };
    }

    private function repeated(): bool
    {
        return $this->kind === SentNotificationKind::CriticalRepeated;
    }
}
