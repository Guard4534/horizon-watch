<?php

namespace Tests\Support;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SyntheticReadings;

/**
 * Stored readings for the page tests, written through the factories: the
 * numbers are the test's own, never a function of the clock or a slug.
 */
final class Readings
{
    public const int STATE_RUN_MINUTES = 30;

    /**
     * The seeder's scripted incidents, written as readings on the seeded
     * organization; every other environment reads healthy. One anomaly
     * each: six in all.
     */
    public static function mockup(Team $team): void
    {
        Environment::query()->where('team_id', $team->id)->each(function (Environment $environment) {
            $status = SyntheticReadings::INCIDENTS[$environment->slug] ?? EnvironmentStatus::Active;

            self::record($environment, $status, match ($status) {
                EnvironmentStatus::Inactive => [AlertRuleMetric::HorizonMasterInactive],
                EnvironmentStatus::Paused => [AlertRuleMetric::HorizonPaused],
                EnvironmentStatus::Degraded => [AlertRuleMetric::QueuePending],
                default => [],
            });
        });
    }

    /**
     * One reading, now: a snapshot and the matching state.
     *
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

        if (in_array($status, [EnvironmentStatus::Unreachable, EnvironmentStatus::Inactive, EnvironmentStatus::Paused], true)
            && ! EnvironmentSnapshot::query()->where('environment_id', $environment->id)->exists()) {
            $snapshots->create([...$row, 'captured_at' => CarbonImmutable::parse($capturedAt)->subMinutes(self::STATE_RUN_MINUTES)]);
        }

        $snapshots->create([...$row, 'captured_at' => $capturedAt]);

        return $states->create([
            'status' => $status,
            'horizon_status' => match ($status) {
                EnvironmentStatus::Inactive => HorizonStatus::Inactive,
                EnvironmentStatus::Paused => HorizonStatus::Paused,
                default => HorizonStatus::Running,
            },
            ...$state,
            'captured_at' => $state['captured_at'] ?? $capturedAt,
        ]);
    }
}
