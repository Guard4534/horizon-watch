<?php

namespace Tests\Support;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\Team;

/**
 * Stored readings for the page tests, written through the factories: the
 * numbers are the test's own, never a function of the clock or a slug.
 */
final class Readings
{
    /**
     * The incidents the phase 1 mockup told, now written as readings on the
     * seeded organization; every other environment reads healthy. Five
     * environments open six anomalies: a paused Horizon opens none.
     */
    public const MOCKUP_INCIDENTS = [
        'fatturaomatic-production' => [EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]],
        'logistics-hub-worker-batch' => [EnvironmentStatus::Unreachable, [AlertRuleMetric::EndpointUnreachable]],
        'mailer-service-worker-batch' => [EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending, AlertRuleMetric::QueueMaxWait]],
        'media-encoder-production' => [EnvironmentStatus::Degraded, [AlertRuleMetric::WorkersMissing]],
        'billing-sync-preprod' => [EnvironmentStatus::Degraded, [AlertRuleMetric::JobsFailedPerHour]],
        'acme-shop-staging' => [EnvironmentStatus::Paused, []],
    ];

    public static function mockup(Team $team): void
    {
        Environment::query()->where('team_id', $team->id)->each(function (Environment $environment) {
            [$status, $breaches] = self::MOCKUP_INCIDENTS[$environment->slug] ?? [EnvironmentStatus::Active, []];

            self::record($environment, $status, $breaches);
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

        $snapshots->create([
            'status' => $status,
            'breaches' => $failed ? [AlertRuleMetric::EndpointUnreachable] : $breaches,
            ...$snapshot,
            'captured_at' => $capturedAt,
        ]);

        return $states->create([
            'status' => $status,
            ...$state,
            'captured_at' => $state['captured_at'] ?? $capturedAt,
        ]);
    }
}
