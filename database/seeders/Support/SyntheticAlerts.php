<?php

namespace Database\Seeders\Support;

use App\Alerts\AlertEngine;
use App\Alerts\EffectiveRules;
use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;

final class SyntheticAlerts
{
    private const int RESOLVED = 10;

    private const array HISTORY = [
        [AlertRuleMetric::QueuePending, 3400, ['pending' => 3400]],
        [AlertRuleMetric::QueueMaxWait, 142, ['waitSeconds' => 142]],
        [AlertRuleMetric::EndpointUnreachable, 6, []],
        [AlertRuleMetric::JobsFailedPerHour, 31, ['failed' => 31]],
        [AlertRuleMetric::WorkersMissing, 5, ['queues' => ['default', 'mail', 'reports', 'exports', 'webhooks']]],
    ];

    private const string MAIL_TARGET = 'ops@example.com';

    private const string WEBHOOK_TARGET = 'hooks.example.com';

    public function seed(Team $team, User $admin): void
    {
        $environments = Environment::query()
            ->where('team_id', $team->id)
            ->with(['application', 'state'])
            ->orderBy('id')
            ->get();

        $this->openFromReadings($environments);
        $this->arrange($team, $admin);
        $this->history($team, $environments);
    }

    /**
     * @param  Collection<int, Environment>  $environments
     */
    private function openFromReadings(Collection $environments): void
    {
        $engine = new AlertEngine(new EffectiveRules);

        Event::fakeFor(function () use ($environments, $engine) {
            foreach ($environments as $environment) {
                $snapshot = EnvironmentSnapshot::query()
                    ->where('environment_id', $environment->id)
                    ->orderByDesc('captured_at')
                    ->orderByDesc('id')
                    ->first();

                if ($snapshot !== null && $environment->state !== null) {
                    $engine->afterReading($environment, $snapshot, $environment->state);
                }
            }
        }, [AlertOpened::class, AlertResolved::class]);
    }

    private function arrange(Team $team, User $admin): void
    {
        $open = Alert::query()->where('team_id', $team->id)->open()->with('environment.state')->orderBy('environment_id')->orderBy('metric')->get();

        foreach ($open as $alert) {
            $alert->opened_at = $this->openedAt($alert);
        }

        $critical = $open->first(fn (Alert $alert) => $alert->severity === AlertSeverity::Critical);
        $paused = $open->first(fn (Alert $alert) => $alert->metric === AlertRuleMetric::HorizonPaused);
        $warning = $open->first(fn (Alert $alert) => $alert->severity === AlertSeverity::Warning && ! $alert->metric->isStateRule());

        foreach ($open->where('severity', AlertSeverity::Critical) as $alert) {
            $alert->forceFill(['notified' => true, 'last_notified_at' => $alert->last_seen_at->subMinutes(9)]);
            $this->log($alert, SentNotificationKind::CriticalAlert, $alert->last_notified_at);
        }

        $critical?->forceFill(['handled_at' => $critical->last_seen_at->subMinutes(20), 'handled_by' => $admin->id]);
        $paused?->forceFill(['muted_indefinitely' => true, 'muted_by' => $admin->id]);
        $warning?->forceFill(['muted_until' => $warning->last_seen_at->addHours(4)->subMinutes(25), 'muted_by' => $admin->id]);

        $open->each(fn (Alert $alert) => $alert->save());
    }

    private function openedAt(Alert $alert): CarbonImmutable
    {
        $since = $alert->environment?->state?->status_since;

        if ($alert->metric->isStateRule() && $since !== null) {
            return $since->addMinutes((int) $alert->threshold);
        }

        return $alert->last_seen_at->subMinutes(12 + crc32($alert->environment_name.$alert->metric->value) % 160);
    }

    /**
     * @param  Collection<int, Environment>  $environments
     */
    private function history(Team $team, Collection $environments): void
    {
        $now = CarbonImmutable::now();

        for ($index = 0; $index < self::RESOLVED; $index++) {
            $environment = $environments[($index * 7) % $environments->count()];
            [$metric, $value, $detail] = self::HISTORY[$index % count(self::HISTORY)];
            $openedAt = $now->subMinutes(150 + $index * 270);
            $critical = $metric->defaultSeverity() === AlertSeverity::Critical;

            $alert = Alert::query()->create([
                'team_id' => $team->id,
                'environment_id' => $environment->id,
                'metric' => $metric,
                'severity' => $metric->defaultSeverity(),
                'application_name' => $environment->application->name,
                'environment_name' => $environment->name,
                'environment_color' => $environment->color,
                'threshold' => $metric->defaultThreshold(),
                'unit' => $metric->unit(),
                'value' => $value,
                'detail' => $detail,
                'opened_at' => $openedAt,
                'last_seen_at' => $openedAt->addMinutes(5 + $index * 3),
                'resolved_at' => $openedAt->addMinutes(6 + $index * 3),
                'notified' => $critical,
                'last_notified_at' => $critical ? $openedAt : null,
                'digested_at' => $critical ? null : $openedAt->addMinutes(15),
                'resolution_notified_at' => $critical ? $openedAt->addMinutes(6 + $index * 3) : null,
            ]);

            if ($critical) {
                $this->log($alert, SentNotificationKind::CriticalAlert, $openedAt);
                $this->log($alert, SentNotificationKind::Resolved, $alert->resolved_at);
            }
        }
    }

    private function log(Alert $alert, SentNotificationKind $kind, CarbonImmutable $at): void
    {
        AlertNotification::query()->create([
            'team_id' => $alert->team_id,
            'alert_id' => $alert->id,
            'kind' => $kind,
            'channel' => NotificationChannel::Mail,
            'target' => self::MAIL_TARGET,
            'status' => DeliveryStatus::Sent,
            'sent_at' => $at,
        ]);

        AlertNotification::query()->create([
            'team_id' => $alert->team_id,
            'alert_id' => $alert->id,
            'kind' => SentNotificationKind::WebhookDelivery,
            'channel' => NotificationChannel::Webhook,
            'target' => self::WEBHOOK_TARGET,
            'status' => DeliveryStatus::Failed,
            'error' => 'unreachable',
            'sent_at' => $at,
        ]);
    }
}
