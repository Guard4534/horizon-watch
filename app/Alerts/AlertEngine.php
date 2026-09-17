<?php

namespace App\Alerts;

use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Models\Alert;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Support\Facades\DB;

#[Bind(AlertEngine::class)]
final class AlertEngine
{
    private const int LISTED_QUEUES = 10;

    public function __construct(private EffectiveRules $rules) {}

    public function afterReading(Environment $environment, EnvironmentSnapshot $snapshot, EnvironmentState $state): void
    {
        $rules = $this->rules->forEnvironment($environment);
        $at = $snapshot->captured_at;
        $open = Alert::query()
            ->open()
            ->where('environment_id', $environment->id)
            ->get()
            ->keyBy(fn (Alert $alert) => $alert->metric->value);

        foreach (AlertRuleMetric::cases() as $metric) {
            $rule = $rules->for($metric);
            $violating = $metric->isStateRule()
                ? $rule->enabled && $this->stateLasts($metric, $state, $at, $rule->threshold)
                : ($snapshot->error === null ? $rule->enabled && $snapshot->breaches->contains($metric) : null);

            if ($violating === null) {
                continue;
            }

            $alert = $open->get($metric->value);

            if ($violating) {
                [$value, $detail] = $this->measure($metric, $snapshot, $state, $at);

                $alert === null
                    ? $this->open($environment, $rule, $at, $value, $detail)
                    : $this->touch($alert, $rule, $at, $value, $detail);
            } elseif ($alert !== null) {
                $this->resolve($alert, $at);
            }
        }
    }

    public function resolveAllFor(Environment $environment, CarbonImmutable $at): void
    {
        Alert::query()
            ->open()
            ->where('environment_id', $environment->id)
            ->update(['resolved_at' => $at]);
    }

    private function stateLasts(AlertRuleMetric $metric, EnvironmentState $state, CarbonImmutable $at, float $minutes): bool
    {
        $status = match ($metric) {
            AlertRuleMetric::EndpointUnreachable => EnvironmentStatus::Unreachable,
            AlertRuleMetric::HorizonMasterInactive => EnvironmentStatus::Inactive,
            default => EnvironmentStatus::Paused,
        };

        if ($state->status !== $status) {
            return false;
        }

        return ($state->status_since ?? $at)->diffInSeconds($at) >= $minutes * 60;
    }

    /**
     * @return array{0: float, 1: array<string, mixed>}
     */
    private function measure(AlertRuleMetric $metric, EnvironmentSnapshot $snapshot, EnvironmentState $state, CarbonImmutable $at): array
    {
        return match ($metric) {
            AlertRuleMetric::QueuePending => [$snapshot->pending, ['pending' => $snapshot->pending]],
            AlertRuleMetric::QueueMaxWait => [$snapshot->max_wait_seconds, ['waitSeconds' => $snapshot->max_wait_seconds]],
            AlertRuleMetric::JobsFailedPerHour => [$snapshot->failed_last_hour, ['failed' => $snapshot->failed_last_hour]],
            AlertRuleMetric::JobRuntime => $this->longestJob($state, $at),
            AlertRuleMetric::WorkersMissing => $this->queuesWithoutWorkers($state),
            default => [(int) ($state->status_since ?? $at)->diffInMinutes($at), []],
        };
    }

    /**
     * @return array{0: float, 1: array<string, mixed>}
     */
    private function longestJob(EnvironmentState $state, CarbonImmutable $at): array
    {
        $longest = null;
        $seconds = 0;

        foreach ($state->pending_jobs as $job) {
            $elapsed = (int) CarbonImmutable::parse($job['reservedAt'])->diffInSeconds($at);

            if ($longest === null || $elapsed > $seconds) {
                [$longest, $seconds] = [$job, $elapsed];
            }
        }

        if ($longest === null) {
            return [0, []];
        }

        return [$seconds, ['job' => $longest['job'], 'queue' => $longest['queue'], 'seconds' => $seconds]];
    }

    /**
     * @return array{0: float, 1: array<string, mixed>}
     */
    private function queuesWithoutWorkers(EnvironmentState $state): array
    {
        $names = [];

        foreach ($state->queues as $queue) {
            if ($queue['workers'] === 0 && $queue['pending'] > 0) {
                $names[] = $queue['name'];
            }
        }

        return [count($names), ['queues' => array_slice($names, 0, self::LISTED_QUEUES)]];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function open(Environment $environment, EffectiveRule $rule, CarbonImmutable $at, float $value, array $detail): void
    {
        $model = new Alert;
        $id = $model->newUniqueId();
        $timestamp = $model->freshTimestamp();

        $row = $model->forceFill([
            'id' => $id,
            'team_id' => $environment->team_id,
            'environment_id' => $environment->id,
            'metric' => $rule->metric,
            'severity' => $rule->severity,
            'application_name' => $environment->application->name,
            'environment_name' => $environment->name,
            'environment_color' => $environment->color,
            'threshold' => $rule->threshold,
            'unit' => $rule->metric->unit(),
            'value' => $value,
            'detail' => $detail,
            'opened_at' => $at,
            'last_seen_at' => $at,
            'muted_indefinitely' => false,
            'notified' => false,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->getAttributes();

        if (DB::table($model->getTable())->insertOrIgnore($row) > 0) {
            AlertOpened::dispatch($id);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function touch(Alert $alert, EffectiveRule $rule, CarbonImmutable $at, float $value, array $detail): void
    {
        $alert->forceFill([
            'last_seen_at' => $at,
            'value' => $value,
            'detail' => $detail,
            'severity' => $rule->severity,
            'threshold' => $rule->threshold,
        ])->save();
    }

    private function resolve(Alert $alert, CarbonImmutable $at): void
    {
        $resolved = Alert::query()
            ->whereKey($alert->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => $at]);

        if ($resolved > 0) {
            AlertResolved::dispatch($alert->id);
        }
    }
}
