<?php

namespace App\Alerts;

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

final readonly class DeliveryPolicy
{
    public function __construct(
        private Recipients $recipients,
        private NotificationDelivery $delivery,
        private EffectiveRules $rules,
    ) {}

    public function onOpened(Alert $alert): void
    {
        $now = CarbonImmutable::now()->utc();

        if ($alert->severity !== AlertSeverity::Critical
            || $alert->state($now) !== AlertState::Open
            || $alert->handled_at !== null
            || $alert->last_notified_at !== null
            || ! $this->watched($alert)) {
            return;
        }

        $this->announce($alert, $now, null);
    }

    public function onResolved(Alert $alert): void
    {
        /** @var Team|null $team */
        $team = $alert->team;
        $resolvedAt = $alert->resolved_at;

        if ($team === null
            || $resolvedAt === null
            || $alert->severity !== AlertSeverity::Critical
            || ! $alert->notified
            || $alert->muted_indefinitely
            || $alert->muted_until?->gt($resolvedAt) === true) {
            return;
        }

        $this->delivery->queueEmails($team, $alert->id, SentNotificationKind::Resolved, $this->recipients->forAlert($alert));
        $this->delivery->queueWebhook($team, $alert->id, SentNotificationKind::WebhookDelivery, WebhookPayload::forAlert($alert, WebhookPayload::RESOLVED));
    }

    public function repeatDue(CarbonImmutable $now): void
    {
        $now = $now->utc();

        $alerts = $this->deliverable($now)
            ->where('alerts.severity', AlertSeverity::Critical)
            ->whereNull('alerts.resolved_at')
            ->whereNull('alerts.handled_at')
            ->unmutedAt($now)
            ->where(fn (Builder $due) => $due
                ->whereNull('alerts.last_notified_at')
                ->orWhere(fn (Builder $default) => $default
                    ->whereNull('notification_settings.team_id')
                    ->where('alerts.last_notified_at', '<=', $now->subMinutes(NotificationSetting::DEFAULT_REPEAT_MINUTES)))
                ->orWhere(fn (Builder $configured) => $configured
                    ->whereNotNull('notification_settings.repeat_minutes')
                    ->whereRaw('alerts.last_notified_at <= ?::timestamptz - make_interval(mins => notification_settings.repeat_minutes)', [$now->toIso8601String()])))
            ->get();

        foreach ($alerts as $alert) {
            $this->announce($alert, $now, $alert->last_notified_at);
        }
    }

    public function digestDue(CarbonImmutable $now): void
    {
        $now = $now->utc();

        $alerts = $this->deliverable($now)
            ->where('alerts.severity', AlertSeverity::Warning)
            ->where(fn (Builder $pending) => $pending
                ->where(fn (Builder $open) => $open
                    ->whereNull('alerts.resolved_at')
                    ->whereNull('alerts.digested_at')
                    ->unmutedAt($now))
                ->orWhere(fn (Builder $resolved) => $resolved
                    ->where('alerts.resolved_at', '>=', $now->subDay())
                    ->where(fn (Builder $undigested) => $undigested
                        ->whereNull('alerts.digested_at')
                        ->orWhereColumn('alerts.digested_at', '<', 'alerts.resolved_at'))
                    ->where('alerts.muted_indefinitely', false)
                    ->where(fn (Builder $unmuted) => $unmuted
                        ->whereNull('alerts.muted_until')
                        ->orWhereColumn('alerts.muted_until', '<=', 'alerts.resolved_at'))))
            ->get();

        foreach ($alerts->groupBy('team_id') as $teamAlerts) {
            $this->digest($teamAlerts, $now);
        }
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     */
    private function digest(Collection $alerts, CarbonImmutable $now): void
    {
        $team = $alerts->first()?->team;

        if ($team === null || QuietHours::forSetting(NotificationSetting::query()->find($team->id), $now)) {
            return;
        }

        $emailable = $alerts->filter(fn (Alert $alert) => $alert->environment !== null
            && $this->rules->forEnvironment($alert->environment)->for($alert->metric)->notifyByEmail);

        foreach ($this->recipients->forDigest($team) as $recipient) {
            $environmentIds = $recipient['environmentIds'];
            $visible = $environmentIds === null
                ? $emailable
                : $emailable->filter(fn (Alert $alert) => in_array($alert->environment_id, $environmentIds, true));

            if ($visible->isEmpty()) {
                continue;
            }

            $this->delivery->queueEmails($team, null, SentNotificationKind::WarningDigest, [$recipient], [
                'alertIds' => array_values($visible->modelKeys()),
                'environmentCount' => $this->environmentCount($visible),
            ]);
        }

        $this->delivery->queueWebhook(
            $team,
            null,
            SentNotificationKind::WarningDigest,
            WebhookPayload::forDigest($team, $alerts->toBase()),
            $this->environmentCount($alerts),
        );

        Alert::query()->whereKey($alerts->modelKeys())->update(['digested_at' => $now]);
    }

    private function announce(Alert $alert, CarbonImmutable $now, ?CarbonImmutable $previous): void
    {
        /** @var Team|null $team */
        $team = $alert->team;

        if ($team === null) {
            return;
        }

        $recipients = $this->recipients->forAlert($alert);

        if ($recipients === [] && ! $this->delivery->hasWebhook($team)) {
            return;
        }

        $claimed = Alert::query()
            ->whereKey($alert->id)
            ->whereNull('resolved_at')
            ->where(fn (Builder $query) => $previous === null
                ? $query->whereNull('last_notified_at')
                : $query->where('last_notified_at', '<=', $previous))
            ->update(['notified' => true, 'last_notified_at' => $now]);

        if ($claimed === 0) {
            return;
        }

        $event = $previous === null ? WebhookPayload::OPENED : WebhookPayload::REPEATED;

        $this->delivery->queueEmails($team, $alert->id, SentNotificationKind::CriticalAlert, $recipients, ['repeated' => $previous !== null]);
        $this->delivery->queueWebhook($team, $alert->id, SentNotificationKind::WebhookDelivery, WebhookPayload::forAlert($alert, $event));
    }

    /**
     * @return Builder<Alert>
     */
    private function deliverable(CarbonImmutable $now): Builder
    {
        return Alert::query()
            ->select('alerts.*')
            ->join('environments', 'environments.id', '=', 'alerts.environment_id')
            ->join('teams', 'teams.id', '=', 'alerts.team_id')
            ->leftJoin('notification_settings', 'notification_settings.team_id', '=', 'alerts.team_id')
            ->whereNull('teams.deleted_at')
            ->where('environments.polling_enabled', true)
            ->where('alerts.opened_at', '<=', $now)
            ->with(['team', 'environment.state'])
            ->orderBy('alerts.opened_at')
            ->orderBy('alerts.id');
    }

    private function watched(Alert $alert): bool
    {
        /** @var Team|null $team */
        $team = $alert->team;

        return $team !== null && $alert->environment?->polling_enabled === true;
    }

    /**
     * @param  BaseCollection<int, Alert>  $alerts
     */
    private function environmentCount(BaseCollection $alerts): int
    {
        return $alerts->pluck('environment_id')->unique()->count();
    }
}
