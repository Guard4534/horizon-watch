<?php

namespace App\Jobs;

use App\Alerts\MailFailed;
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
use App\Support\SafeReport;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

class SendAlertEmail extends DeliverAlert
{
    public bool $unrestricted = false;

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
        bool $unrestricted = false,
    ) {
        parent::__construct($teamId, $alertId, $kind);

        $this->unrestricted = $unrestricted;
    }

    public function handle(Recipients $recipients, VisibleEnvironments $visible): void
    {
        if ($this->recorded()) {
            return;
        }

        $team = Team::query()->find($this->teamId);
        $email = $team === null ? null : $this->address($team, $recipients);

        if ($team === null || $email === null) {
            return;
        }

        $member = $this->restrictedMember($team, $recipients, $email);

        $notification = match ($this->kind) {
            SentNotificationKind::Test => new TestNotification($team),
            SentNotificationKind::WarningDigest => $this->digest($team, $visible, $member),
            default => $this->aboutAlert($team, $visible, $member, $email),
        };

        if ($notification === null) {
            return;
        }

        try {
            DB::transaction(function () use ($email, $notification): void {
                $this->log(DeliveryStatus::Sent, null, $email, $this->countOf($notification));

                Notifications::route('mail', $email)->notifyNow($notification->locale($this->locale));
            });
        } catch (Throwable $exception) {
            $failure = MailFailed::from($exception);

            if ($failure->permanent() || $this->attempts() >= $this->tries) {
                SafeReport::of('Sending an alert email', $exception);
            }

            if ($failure->permanent()) {
                $this->fail($failure);

                return;
            }

            throw $failure;
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
        return $exception instanceof MailFailed ? $exception->reason : DeliveryError::Mail;
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

    private function restrictedMember(Team $team, Recipients $recipients, string $email): ?User
    {
        if ($this->userId === null || ($this->unrestricted && $recipients->extraAddress($team, Recipients::addressKey($email)) !== null)) {
            return null;
        }

        return User::query()->find($this->userId);
    }

    private function digest(Team $team, VisibleEnvironments $visible, ?User $member): ?WarningDigestNotification
    {
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

    private function aboutAlert(Team $team, VisibleEnvironments $visible, ?User $member, string $email): ?Notification
    {
        $alert = $this->alertId === null
            ? null
            : Alert::query()->where('team_id', $team->id)->with(['team', 'environment.state'])->find($this->alertId);

        if ($alert === null
            || ($member !== null && ! $visible->sees($team, $member, $alert->environment_id))
            || ! $this->stillDueTo($alert, $email)) {
            return null;
        }

        return $this->kind === SentNotificationKind::Resolved
            ? new ResolvedNotification($alert)
            : new AlertNotification($alert, repeated: $this->kind === SentNotificationKind::CriticalRepeated);
    }
}
