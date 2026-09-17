<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\SeriesRange;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Monitoring\StoredReadings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // 10:08:00 UTC: 30 seconds into a 3h bucket (225 s wide, epoch-aligned),
    // so "now" is not on a bucket edge.
    $this->now = CarbonImmutable::parse('2026-09-17 10:08:00', 'UTC');
    $this->travelTo($this->now);
    $this->readings = app(StoredReadings::class);
    // The 3h grid: the last bucket starts at 10:07:30, the first one 47
    // buckets before it.
    $this->lastBucket = CarbonImmutable::parse('2026-09-17 10:07:30', 'UTC');
});

test('the latest state carries the numbers of the latest snapshot, in one query', function () {
    $read = Environment::factory()->create(['poll_interval_seconds' => 15]);
    $unread = Environment::factory()->create();

    EnvironmentSnapshot::factory()->for($read)->create(['captured_at' => $this->now->subMinute(), 'pending' => 1, 'workers' => 1]);
    EnvironmentSnapshot::factory()->for($read)->create(['captured_at' => $this->now->subSeconds(15), 'pending' => 42, 'workers' => 7, 'node_count' => 2]);
    EnvironmentState::factory()->for($read)->create(['captured_at' => $this->now->subSeconds(15)]);

    DB::enableQueryLog();
    $latest = $this->readings->latestFor([$read, $unread]);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(array_keys($latest))->toBe([$read->id, $unread->id])
        ->and($latest[$unread->id])->toBeNull()
        ->and($latest[$read->id]->getAttribute('snapshot_pending'))->toBe(42)
        ->and($latest[$read->id]->getAttribute('snapshot_workers'))->toBe(7)
        ->and($latest[$read->id]->getAttribute('snapshot_node_count'))->toBe(2)
        ->and($this->readings->latestFor([]))->toBe([]);
});

test('an environment is stale past three poll intervals, not before', function () {
    $environment = Environment::factory()->create(['poll_interval_seconds' => 30]);

    $state = fn (int $secondsAgo) => EnvironmentState::factory()->make([
        'environment_id' => $environment->id,
        'captured_at' => $this->now->subSeconds($secondsAgo),
    ]);

    expect($this->readings->isStale($environment, $state(90), $this->now))->toBeFalse()
        ->and($this->readings->isStale($environment, $state(91), $this->now))->toBeTrue()
        ->and($this->readings->isStale($environment, $state(5), $this->now))->toBeFalse()
        ->and($this->readings->isStale($environment, null, $this->now))->toBeTrue();
});

test('the throughput series averages each environment per bucket, then sums them', function () {
    $alpha = Environment::factory()->create();
    $bravo = Environment::factory()->create();
    $last = $this->lastBucket;

    // Last bucket: alpha averages 15, bravo 5.
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->addSeconds(10), 'jobs_per_minute' => 10]);
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->addSeconds(200), 'jobs_per_minute' => 20]);
    EnvironmentSnapshot::factory()->for($bravo)->create(['captured_at' => $last, 'jobs_per_minute' => 5]);
    // A failed reading measured nothing: it does not pull the average down.
    EnvironmentSnapshot::factory()->for($bravo)->failed()->create(['captured_at' => $last->addSeconds(30)]);
    // Three buckets earlier: alpha alone.
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->subSeconds(3 * 225), 'jobs_per_minute' => 8]);
    // The first bucket, on its very edge.
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->subSeconds(47 * 225), 'jobs_per_minute' => 3]);
    // Just before the range: ignored.
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->subSeconds(47 * 225 + 1), 'jobs_per_minute' => 999]);

    $series = $this->readings->throughputSeries([$alpha->id, $bravo->id], SeriesRange::ThreeHours);

    $expected = array_fill(0, 48, 0);
    $expected[0] = 3;
    $expected[44] = 8;
    $expected[47] = 20;

    expect($series)->toBe($expected)
        ->and($this->readings->throughputSeries([$bravo->id], SeriesRange::ThreeHours)[47])->toBe(5)
        ->and($this->readings->throughputSeries([], SeriesRange::ThreeHours))->toBe(array_fill(0, 48, 0));
});

test('each range has 48 buckets of its own width', function (SeriesRange $range, int $step) {
    $environment = Environment::factory()->create();
    $last = CarbonImmutable::createFromTimestampUTC(intdiv($this->now->getTimestamp(), $step) * $step);

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last, 'jobs_per_minute' => 4, 'max_wait_seconds' => 9]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last->subSecond(), 'jobs_per_minute' => 6, 'max_wait_seconds' => 3]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last->subSeconds($step), 'jobs_per_minute' => 2, 'max_wait_seconds' => 1]);

    $throughput = $this->readings->throughputSeries([$environment->id], $range);
    $maxWait = $this->readings->maxWaitSeries($environment->id, $range);

    expect($throughput)->toHaveCount(48)
        ->and(array_slice($throughput, -2))->toBe([4, 4])
        ->and($maxWait)->toHaveCount(48)
        ->and(array_slice($maxWait, -2))->toBe([3, 9])
        ->and(array_sum($maxWait))->toBe(12);
})->with([
    '3h' => [SeriesRange::ThreeHours, 225],
    '24h' => [SeriesRange::Day, 1800],
    '7d' => [SeriesRange::Week, 12600],
]);

test('the max wait series keeps the worst wait of each bucket', function () {
    $environment = Environment::factory()->create();
    $other = Environment::factory()->create();
    $last = $this->lastBucket;

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last->addSeconds(5), 'max_wait_seconds' => 12]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last->addSeconds(20), 'max_wait_seconds' => 40]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $last->subSeconds(225), 'max_wait_seconds' => 7]);
    EnvironmentSnapshot::factory()->for($other)->create(['captured_at' => $last, 'max_wait_seconds' => 500]);

    $expected = array_fill(0, 48, 0);
    $expected[46] = 7;
    $expected[47] = 40;

    expect($this->readings->maxWaitSeries($environment->id, SeriesRange::ThreeHours))->toBe($expected);
});

test('an open anomaly dates from the start of its uninterrupted run', function () {
    $environment = Environment::factory()->create();
    $at = fn (int $minutesAgo) => ['captured_at' => $this->now->subMinutes($minutesAgo)];

    // Pending breached 30 and 25 minutes ago, then cleared, then back from
    // 10 minutes ago: the run restarts after the gap.
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(30));
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(25));
    EnvironmentSnapshot::factory()->for($environment)->create($at(20));
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(10));
    // Max wait joins later, on its own run.
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending, AlertRuleMetric::QueueMaxWait])->create($at(5));
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait, AlertRuleMetric::QueuePending])->create($at(1));

    $anomalies = $this->readings->openAnomalies([$environment]);

    expect($anomalies)->toHaveKey($environment->id)
        ->and(array_map(fn ($anomaly) => [$anomaly['metric'], $anomaly['since']->toIso8601String()], $anomalies[$environment->id]))
        ->toBe([
            [AlertRuleMetric::QueuePending, $this->now->subMinutes(10)->toIso8601String()],
            [AlertRuleMetric::QueueMaxWait, $this->now->subMinutes(5)->toIso8601String()],
        ]);
});

test('an anomaly with no gap behind it dates from the oldest reading', function () {
    $environment = Environment::factory()->create();

    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::JobRuntime])->create(['captured_at' => $this->now->subHour()]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::JobRuntime])->create(['captured_at' => $this->now]);

    expect($this->readings->openAnomalies([$environment])[$environment->id][0]['since']->equalTo($this->now->subHour()))->toBeTrue();
});

test('down anomalies follow the status, and a healthy or paused latest reading opens none', function () {
    $unreachable = Environment::factory()->create();
    $inactive = Environment::factory()->create();
    $recovered = Environment::factory()->create();
    $paused = Environment::factory()->create();
    $at = fn (int $minutesAgo) => ['captured_at' => $this->now->subMinutes($minutesAgo)];

    // Degraded, then unreachable twice: the outage starts with the first
    // failed reading, whatever the breaches before it.
    EnvironmentSnapshot::factory()->for($unreachable)->degraded([AlertRuleMetric::QueuePending])->create($at(9));
    EnvironmentSnapshot::factory()->for($unreachable)->failed()->create($at(6));
    EnvironmentSnapshot::factory()->for($unreachable)->failed()->create($at(3));

    // A Horizon without masters: the status carries it, even on a row whose
    // breach list forgot it.
    EnvironmentSnapshot::factory()->for($inactive)->create([...$at(8), 'status' => EnvironmentStatus::Inactive, 'breaches' => []]);
    EnvironmentSnapshot::factory()->for($inactive)->create([...$at(4), 'status' => EnvironmentStatus::Inactive, 'breaches' => [AlertRuleMetric::HorizonMasterInactive]]);

    EnvironmentSnapshot::factory()->for($recovered)->failed()->create($at(5));
    EnvironmentSnapshot::factory()->for($recovered)->create($at(1));

    EnvironmentSnapshot::factory()->for($paused)->create([...$at(1), 'status' => EnvironmentStatus::Paused]);

    $anomalies = $this->readings->openAnomalies([$unreachable, $inactive, $recovered, $paused]);

    expect(array_keys($anomalies))->toEqualCanonicalizing([$unreachable->id, $inactive->id])
        ->and($anomalies[$unreachable->id])->toHaveCount(1)
        ->and($anomalies[$unreachable->id][0]['metric'])->toBe(AlertRuleMetric::EndpointUnreachable)
        ->and($anomalies[$unreachable->id][0]['since']->equalTo($this->now->subMinutes(6)))->toBeTrue()
        ->and($anomalies[$inactive->id])->toHaveCount(1)
        ->and($anomalies[$inactive->id][0]['metric'])->toBe(AlertRuleMetric::HorizonMasterInactive)
        ->and($anomalies[$inactive->id][0]['since']->equalTo($this->now->subMinutes(8)))->toBeTrue()
        ->and($this->readings->openAnomalies([]))->toBe([]);
});

test('critical anomalies come first', function () {
    $environment = Environment::factory()->create();

    EnvironmentSnapshot::factory()->for($environment)->create([
        'captured_at' => $this->now,
        'status' => EnvironmentStatus::Inactive,
        'breaches' => [AlertRuleMetric::WorkersMissing, AlertRuleMetric::HorizonMasterInactive],
    ]);

    expect(array_map(fn ($anomaly) => $anomaly['metric'], $this->readings->openAnomalies([$environment])[$environment->id]))
        ->toBe([AlertRuleMetric::HorizonMasterInactive, AlertRuleMetric::WorkersMissing]);
});
