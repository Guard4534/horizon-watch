<?php

namespace Tests\Support;

use App\Alerts\AlertEngine;
use App\Alerts\EffectiveRules;
use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

final class Readings
{
    public const int STATE_RUN_MINUTES = 30;

    /**
     * @param  list<AlertRuleMetric>  $breaches
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $state
     */
    public static function record(
        Environment $environment,
        EnvironmentStatus $status = EnvironmentStatus::Active,
        array $breaches = [],
        array $snapshot = [],
        array $state = [],
    ): EnvironmentState {
        $failed = $status === EnvironmentStatus::Unreachable;
        $capturedAt = $snapshot['captured_at'] ?? now();

        $snapshots = EnvironmentSnapshot::factory()->for($environment);
        $states = EnvironmentState::factory()->for($environment);

        if ($failed) {
            $snapshots = $snapshots->failed();
            $states = $states->failed();
        }

        $row = [
            'status' => $status,
            'breaches' => $failed ? [AlertRuleMetric::EndpointUnreachable] : $breaches,
            ...$snapshot,
        ];

        $stored = $snapshots->create([...$row, 'captured_at' => $capturedAt]);

        $recorded = $states->create([
            'status' => $status,
            'horizon_status' => match ($status) {
                EnvironmentStatus::Inactive => HorizonStatus::Inactive,
                EnvironmentStatus::Paused => HorizonStatus::Paused,
                default => HorizonStatus::Running,
            },
            'status_since' => CarbonImmutable::parse($capturedAt)->subMinutes(
                in_array($status, [EnvironmentStatus::Unreachable, EnvironmentStatus::Inactive, EnvironmentStatus::Paused], true) ? self::STATE_RUN_MINUTES : 0,
            ),
            ...$state,
            'captured_at' => $state['captured_at'] ?? $capturedAt,
        ]);

        Event::fakeFor(
            fn () => (new AlertEngine(new EffectiveRules))->afterReading($environment, $stored->fresh(), $recorded->fresh()),
            [AlertOpened::class, AlertResolved::class],
        );

        return $recorded;
    }
}
