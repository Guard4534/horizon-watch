<?php

namespace App\Alerts;

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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

        $this->announce($alert, $now, null, $this->reach($alert->team));
    }

    public function onResolved(Alert $alert): void
    {
        /** @var Team|null $team */
        $team = $alert->team;
        $resolvedAt = $alert->resolved_at;

        if ($team === null
            || $resolvedAt === null
            || ! $alert->notified
            || $alert->resolution_notified_at !== null
            || $alert->muted_indefinitely
            || $alert->muted_until?->gt($resolvedAt) === true) {
            return;
        }

        $this->announceResolution($alert, CarbonImmutable::now()->utc(), $this->reach($team));
    }

    public function repeatDue(CarbonImmutable $now): void
    {
        $now = $now->utc();
        $minute = $now->startOfMinute()->toIso8601String();

        $alerts = $this->deliverable($now)
            ->where('alerts.severity', AlertSeverity::Critical)
            ->whereNull('alerts.resolved_at')
            ->whereNull('alerts.handled_at')
            ->unmutedAt($now)
            ->where(fn (Builder $due) => $due
                ->whereNull('alerts.last_notified_at')
                ->orWhere(fn (Builder $default) => $default
                    ->whereNull('notification_settings.team_id')
                    ->whereRaw("date_trunc('minute', alerts.last_notified_at) <= ?::timestamptz - make_interval(mins => ?)", [$minute, NotificationSetting::defaultRepeatMinutes()]))
                ->orWhere(fn (Builder $configured) => $configured
                    ->whereNotNull('notification_settings.repeat_minutes')
                    ->whereRaw("date_trunc('minute', alerts.last_notified_at) <= ?::timestamptz - make_interval(mins => notification_settings.repeat_minutes)", [$minute])))
            ->get();

        $this->eachByTeam($alerts, 'repetitions', function (Alert $alert, array $reach) use ($now): void {
            $this->announce($alert, $now, $alert->last_notified_at, $reach);
        });
    }

    public function resolutionsDue(CarbonImmutable $now): void
    {
        $now = $now->utc();

        $alerts = $this->deliverable($now)
            ->whereNotNull('alerts.resolved_at')
            ->where('alerts.resolved_at', '>=', $now->subMinutes(config()->integer('horizon-watch.notifications.resolution_catch_up_minutes')))
            ->where('alerts.notified', true)
            ->whereNull('alerts.resolution_notified_at')
            ->where('alerts.muted_indefinitely', false)
            ->where(fn (Builder $unmuted) => $unmuted
                ->whereNull('alerts.muted_until')
                ->orWhereColumn('alerts.muted_until', '<=', 'alerts.resolved_at'))
            ->get();

        $this->eachByTeam($alerts, 'resolutions', function (Alert $alert, array $reach) use ($now): void {
            $this->announceResolution($alert, $now, $reach);
        });
    }

    public function digestDue(CarbonImmutable $now): void
    {
        $now = $now->utc();

        $alerts = $this->deliverable($now)
            ->where(fn (Builder $pending) => $this->pendingDigest($pending, $now))
            ->get();

        foreach ($alerts->groupBy('team_id') as $teamId => $teamAlerts) {
            try {
                $this->digest($teamAlerts, $now);
            } catch (Throwable $exception) {
                $this->reportFailure('digest', (int) $teamId, $exception);
            }
        }
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     */
    private function digest(Collection $alerts, CarbonImmutable $now): void
    {
        $team = $alerts->first()?->team;

        if ($team === null || QuietHours::holdsDigest(NotificationSetting::query()->find($team->id), $now)) {
            return;
        }

        [$audience, $hasWebhook] = $this->reach($team);

        DB::transaction(function () use ($alerts, $now, $team, $audience, $hasWebhook): void {
            $claimed = Alert::query()
                ->whereKey($alerts->modelKeys())
                ->where(fn (Builder $pending) => $this->pendingDigest($pending, $now))
                ->orderBy('opened_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($claimed->isEmpty()) {
                return;
            }

            Alert::query()->whereKey($claimed->modelKeys())->update(['digested_at' => DB::raw('coalesce(resolved_at, last_seen_at)')]);

            $claimed->load(['team', 'environment']);

            $this->queueDigest($team, $claimed, $audience, $hasWebhook);
        });
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     * @param  list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>  $audience
     */
    private function queueDigest(Team $team, Collection $alerts, array $audience, bool $hasWebhook): void
    {
        $emailable = $alerts->filter(fn (Alert $alert) => $alert->environment !== null
            && $this->rules->forEnvironment($alert->environment)->for($alert->metric)->notifyByEmail);

        foreach ($audience as $recipient) {
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

        if ($hasWebhook) {
            $this->delivery->dispatchWebhook(
                $team,
                null,
                SentNotificationKind::WarningDigest,
                WebhookPayload::forDigest($team, $alerts->toBase()),
                $this->environmentCount($alerts),
            );
        }
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    private function pendingDigest(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('alerts.severity', AlertSeverity::Warning)
            ->where(fn (Builder $pending) => $pending
                ->where(fn (Builder $open) => $open
                    ->whereNull('alerts.resolved_at')
                    ->whereNull('alerts.digested_at')
                    ->unmutedAt($now))
                ->orWhere(fn (Builder $resolved) => $resolved
                    ->whereNotNull('alerts.resolved_at')
                    ->where(fn (Builder $undigested) => $undigested
                        ->whereNull('alerts.digested_at')
                        ->orWhereColumn('alerts.digested_at', '<', 'alerts.resolved_at'))
                    ->where('alerts.muted_indefinitely', false)
                    ->where(fn (Builder $unmuted) => $unmuted
                        ->whereNull('alerts.muted_until')
                        ->orWhereColumn('alerts.muted_until', '<=', 'alerts.resolved_at'))));
    }

    /**
     * @param  array{0: list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>, 1: bool}  $reach
     */
    private function announce(Alert $alert, CarbonImmutable $now, ?CarbonImmutable $previous, array $reach): void
    {
        /** @var Team|null $team */
        $team = $alert->team;
        [$audience, $hasWebhook] = $reach;

        if ($team === null) {
            return;
        }

        $recipients = $this->recipients->forAlertIn($alert, $audience);

        if ($recipients === [] && ! $hasWebhook) {
            return;
        }

        DB::transaction(function () use ($alert, $now, $previous, $team, $recipients, $hasWebhook): void {
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

            if ($hasWebhook) {
                $this->delivery->dispatchWebhook($team, $alert->id, SentNotificationKind::WebhookDelivery, WebhookPayload::forAlert($alert, $event));
            }
        });
    }

    /**
     * @param  array{0: list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>, 1: bool}  $reach
     */
    private function announceResolution(Alert $alert, CarbonImmutable $now, array $reach): void
    {
        /** @var Team|null $team */
        $team = $alert->team;
        [$audience, $hasWebhook] = $reach;

        if ($team === null) {
            return;
        }

        $recipients = $this->recipients->forAlertIn($alert, $audience);

        DB::transaction(function () use ($alert, $now, $team, $recipients, $hasWebhook): void {
            $claimed = Alert::query()
                ->whereKey($alert->id)
                ->whereNotNull('resolved_at')
                ->where('notified', true)
                ->whereNull('resolution_notified_at')
                ->update(['resolution_notified_at' => $now]);

            if ($claimed === 0) {
                return;
            }

            $this->delivery->queueEmails($team, $alert->id, SentNotificationKind::Resolved, $recipients);

            if ($hasWebhook) {
                $this->delivery->dispatchWebhook($team, $alert->id, SentNotificationKind::WebhookDelivery, WebhookPayload::forAlert($alert, WebhookPayload::RESOLVED));
            }
        });
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     * @param  callable(Alert, array{0: list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>, 1: bool}): void  $send
     */
    private function eachByTeam(Collection $alerts, string $step, callable $send): void
    {
        foreach ($alerts->groupBy('team_id') as $teamId => $teamAlerts) {
            try {
                $reach = $this->reach($teamAlerts->first()?->team);
            } catch (Throwable $exception) {
                $this->reportFailure($step, (int) $teamId, $exception);

                continue;
            }

            foreach ($teamAlerts as $alert) {
                try {
                    $send($alert, $reach);
                } catch (Throwable $exception) {
                    $this->reportFailure($step, (int) $teamId, $exception);
                }
            }
        }
    }

    /**
     * @return array{0: list<array{email: string, locale: string, user: ?User, environmentIds: list<int>|null, extra: bool}>, 1: bool}
     */
    private function reach(?Team $team): array
    {
        return $team === null ? [[], false] : [$this->recipients->forDigest($team), $this->delivery->hasWebhook($team)];
    }

    private function reportFailure(string $step, int $teamId, Throwable $exception): void
    {
        report(new RuntimeException(sprintf(
            'Alert %s of team %d threw %s at %s:%d.',
            $step,
            $teamId,
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
        )));
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
