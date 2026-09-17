<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SyntheticReadings;

beforeEach(function () {
    $this->until = CarbonImmutable::parse('2026-09-17 10:00:00');
    $this->travelTo($this->until);
    $this->readings = new SyntheticReadings;
});

function syntheticEnvironment(Team $team, string $application, string $state): Environment
{
    $application = Application::factory()->for($team)->create(['name' => $application]);

    return Environment::factory()->for($application)->{$state}()->create()->fresh(['application']);
}

/**
 * @return list<array<string, mixed>>
 */
function syntheticRows(Environment $environment): array
{
    return $environment->snapshots()->orderBy('captured_at')->get()
        ->map(fn (EnvironmentSnapshot $snapshot) => $snapshot->makeHidden(['id', 'environment_id'])->toArray())
        ->all();
}

test('one snapshot per step over the window, the last one at the end, and a state', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($environment, $this->until, hours: 2, stepMinutes: 10);

    $captured = $environment->snapshots()->orderBy('captured_at')->pluck('captured_at');

    expect($captured)->toHaveCount(12)
        ->and($captured->first()->equalTo($this->until->subMinutes(110)))->toBeTrue()
        ->and($captured->last()->equalTo($this->until))->toBeTrue()
        ->and($environment->state->captured_at->equalTo($this->until))->toBeTrue();
});

test('the same environment and time give the same readings', function () {
    $first = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');
    $second = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($first, $this->until, hours: 3);
    $this->readings->seed($second, $this->until, hours: 3);

    $state = fn (Environment $environment) => $environment->state()->first()
        ->makeHidden(['id', 'environment_id', 'created_at', 'updated_at'])->toArray();

    expect($second->slug)->toBe($first->slug)
        ->and(syntheticRows($second))->toBe(syntheticRows($first))
        ->and($state($second))->toBe($state($first));
});

test('the numbers move over the window', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($environment, $this->until, hours: 3);

    expect($environment->snapshots()->distinct()->pluck('jobs_per_minute')->count())->toBeGreaterThan(1);
});

test('the scripted incidents are always there, keyed by slug', function (string $application, string $state, string $slug, EnvironmentStatus $status, AlertRuleMetric $breach) {
    $environment = syntheticEnvironment(Team::factory()->create(), $application, $state);

    $this->readings->seed($environment, $this->until);

    $snapshots = $environment->snapshots()->get();

    expect($environment->slug)->toBe($slug)
        ->and($snapshots)->toHaveCount(288)
        ->and($snapshots->every(fn (EnvironmentSnapshot $snapshot) => $snapshot->status === $status))->toBeTrue()
        ->and($snapshots->every(fn (EnvironmentSnapshot $snapshot) => $snapshot->breaches->contains($breach)))->toBeTrue()
        ->and($environment->state->status)->toBe($status);
})->with([
    ['Fatturaomatic', 'production', 'fatturaomatic-production', EnvironmentStatus::Inactive, AlertRuleMetric::HorizonMasterInactive],
    ['Mailer Service', 'workerBatch', 'mailer-service-worker-batch', EnvironmentStatus::Degraded, AlertRuleMetric::JobRuntime],
    ['Logistics Hub', 'workerBatch', 'logistics-hub-worker-batch', EnvironmentStatus::Unreachable, AlertRuleMetric::EndpointUnreachable],
    ['Media Encoder', 'production', 'media-encoder-production', EnvironmentStatus::Degraded, AlertRuleMetric::JobRuntime],
    ['Billing Sync', 'preprod', 'billing-sync-preprod', EnvironmentStatus::Degraded, AlertRuleMetric::JobRuntime],
]);

test('the paused incident has no breach', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'Acme Shop', 'staging');

    $this->readings->seed($environment, $this->until);

    expect($environment->slug)->toBe('acme-shop-staging')
        ->and($environment->snapshots()->get()->every(
            fn (EnvironmentSnapshot $snapshot) => $snapshot->status === EnvironmentStatus::Paused && $snapshot->breaches->isEmpty(),
        ))->toBeTrue()
        ->and(collect($environment->state->nodes)->pluck('status')->unique()->all())->toBe(['paused']);
});

test('a healthy environment stays active, without breaches', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($environment, $this->until);

    expect($environment->slug)->toBe('crm-bridge-production')
        ->and($environment->snapshots()->get()->every(
            fn (EnvironmentSnapshot $snapshot) => $snapshot->status === EnvironmentStatus::Active && $snapshot->breaches->isEmpty(),
        ))->toBeTrue();
});

test('a healthy environment stores no reserved job, so none can age into a long-running one', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($environment, $this->until, hours: 1);

    expect($environment->state->status)->toBe(EnvironmentStatus::Active)
        ->and($environment->state->pending_jobs)->toBe([]);
});

test('a degraded environment stores reserved jobs already past the runtime threshold', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'Media Encoder', 'production');

    $this->readings->seed($environment, $this->until, hours: 1);

    $threshold = AlertRuleMetric::JobRuntime->defaultThreshold();

    expect($environment->state->pending_jobs)->toHaveCount(3)
        ->and(collect($environment->state->pending_jobs)->contains(
            fn (array $job) => CarbonImmutable::parse($job['reservedAt'])->diffInSeconds($this->until) > $threshold,
        ))->toBeTrue();
});

test('an unreachable environment records nothing measured but keeps a previous detail', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'Logistics Hub', 'workerBatch');

    $this->readings->seed($environment, $this->until, hours: 1);

    $snapshot = $environment->snapshots()->latest('captured_at')->first();
    $state = $environment->state;

    expect($snapshot->error)->toBe(ReadingError::Unreachable)
        ->and($snapshot->pending)->toBe(0)
        ->and($snapshot->node_count)->toBe(0)
        ->and($snapshot->latency_ms)->toBeNull()
        ->and($state->error)->toBe(ReadingError::Unreachable)
        ->and($state->latency_ms)->toBeNull()
        ->and($state->nodes)->toHaveCount(2)
        ->and($state->queues)->toHaveCount(3)
        // The kept detail predates the one-hour outage, jobs included.
        ->and(collect($state->failed_jobs)->every(
            fn (array $job) => CarbonImmutable::parse($job['failedAt'])->lessThan($this->until->subHour()),
        ))->toBeTrue()
        ->and(collect($state->pending_jobs)->every(
            fn (array $job) => CarbonImmutable::parse($job['reservedAt'])->lessThan($this->until->subHour()),
        ))->toBeTrue()
        ->and($state->pending_jobs)->not->toBe([]);
});

test('an inactive environment lists no node and no worker, but its queues still fill', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'Fatturaomatic', 'production');

    $this->readings->seed($environment, $this->until, hours: 1);

    $snapshot = $environment->snapshots()->latest('captured_at')->first();

    expect($snapshot->workers)->toBe(0)
        ->and($snapshot->jobs_per_minute)->toBe(0)
        ->and($snapshot->node_count)->toBe(0)
        ->and($snapshot->pending)->toBeGreaterThan(0)
        ->and($environment->state->nodes)->toBe([]);
});

test('the snapshot totals agree with the state detail of the same moment', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'Media Encoder', 'production');

    $this->readings->seed($environment, $this->until, hours: 1);

    $snapshot = $environment->snapshots()->latest('captured_at')->first();
    $state = $environment->state;

    expect($snapshot->pending)->toBe(collect($state->queues)->sum('pending'))
        ->and($snapshot->max_wait_seconds)->toBe(collect($state->queues)->max('waitSeconds'))
        ->and($snapshot->node_count)->toBe(count($state->nodes))
        ->and($snapshot->latency_ms)->toBe($state->latency_ms)
        ->and($state->nodes)->toHaveCount(3)
        ->and($state->nodes[0]['hostname'])->toBe('queue-01.media-encoder.internal')
        ->and($state->failed_jobs)->toHaveCount(5)
        ->and($state->failed_jobs[0]['failedAt'])->toBe($this->until->subMinutes(2)->toIso8601String());
});

test('seeding again replaces the state rather than adding one', function () {
    $environment = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');

    $this->readings->seed($environment, $this->until->subDay(), hours: 1);
    $this->readings->seed($environment, $this->until, hours: 1);

    expect(EnvironmentState::query()->where('environment_id', $environment->id)->count())->toBe(1)
        ->and($environment->state->captured_at->equalTo($this->until))->toBeTrue();
});

test('synthetic nodes are dated from the reading that listed them, and failed jobs are counted over a day', function () {
    $healthy = syntheticEnvironment(Team::factory()->create(), 'CRM Bridge', 'production');
    $unreachable = syntheticEnvironment(Team::factory()->create(), 'Logistics Hub', 'workerBatch');

    $this->readings->seed($healthy, $this->until, hours: 1);
    $this->readings->seed($unreachable, $this->until, hours: 1);

    expect(array_unique(array_column($healthy->state->nodes, 'seenAt')))->toBe([$this->until->toIso8601String()])
        // The kept detail is the reading from before the outage, and so are its nodes.
        ->and(array_unique(array_column($unreachable->state->nodes, 'seenAt')))->toBe([$this->until->subHour()->toIso8601String()])
        ->and(EnvironmentSnapshot::query()->distinct()->pluck('failed_window_minutes')->all())->toBe([1440]);
});
