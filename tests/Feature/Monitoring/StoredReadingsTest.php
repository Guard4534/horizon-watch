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
    $this->now = CarbonImmutable::parse('2026-09-17 10:08:00', 'UTC');
    $this->travelTo($this->now);
    $this->readings = app(StoredReadings::class);
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
        ->and($this->readings->isStale($environment, null, $this->now->addSeconds(90)))->toBeFalse()
        ->and($this->readings->isStale($environment, null, $this->now->addSeconds(91)))->toBeTrue();
});

test('the throughput series averages each environment per bucket, then sums them', function () {
    $alpha = Environment::factory()->create();
    $bravo = Environment::factory()->create();
    $last = $this->lastBucket;

    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->addSeconds(10), 'jobs_per_minute' => 10]);
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->addSeconds(200), 'jobs_per_minute' => 20]);
    EnvironmentSnapshot::factory()->for($bravo)->create(['captured_at' => $last, 'jobs_per_minute' => 5]);
    EnvironmentSnapshot::factory()->for($bravo)->failed()->create(['captured_at' => $last->addSeconds(30)]);
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->subSeconds(3 * 225), 'jobs_per_minute' => 8]);
    EnvironmentSnapshot::factory()->for($alpha)->create(['captured_at' => $last->subSeconds(47 * 225), 'jobs_per_minute' => 3]);
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

    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(30));
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(25));
    EnvironmentSnapshot::factory()->for($environment)->create($at(20));
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create($at(10));
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

test('down anomalies and the pause follow the status, and a healthy latest reading opens none', function () {
    $unreachable = Environment::factory()->create();
    $inactive = Environment::factory()->create();
    $recovered = Environment::factory()->create();
    $paused = Environment::factory()->create();
    $at = fn (int $minutesAgo) => ['captured_at' => $this->now->subMinutes($minutesAgo)];

    EnvironmentSnapshot::factory()->for($unreachable)->degraded([AlertRuleMetric::QueuePending])->create($at(9));
    EnvironmentSnapshot::factory()->for($unreachable)->failed()->create($at(6));
    EnvironmentSnapshot::factory()->for($unreachable)->failed()->create($at(3));

    EnvironmentSnapshot::factory()->for($inactive)->create([...$at(8), 'status' => EnvironmentStatus::Inactive, 'breaches' => []]);
    EnvironmentSnapshot::factory()->for($inactive)->create([...$at(4), 'status' => EnvironmentStatus::Inactive, 'breaches' => [AlertRuleMetric::HorizonMasterInactive]]);

    EnvironmentSnapshot::factory()->for($recovered)->failed()->create($at(5));
    EnvironmentSnapshot::factory()->for($recovered)->create($at(1));

    EnvironmentSnapshot::factory()->for($paused)->create([...$at(17), 'status' => EnvironmentStatus::Paused, 'breaches' => []]);
    EnvironmentSnapshot::factory()->for($paused)->create([...$at(1), 'status' => EnvironmentStatus::Paused, 'breaches' => [AlertRuleMetric::HorizonPaused]]);

    $anomalies = $this->readings->openAnomalies([$unreachable, $inactive, $recovered, $paused]);

    expect(array_keys($anomalies))->toEqualCanonicalizing([$unreachable->id, $inactive->id, $paused->id])
        ->and($anomalies[$paused->id])->toHaveCount(1)
        ->and($anomalies[$paused->id][0]['metric'])->toBe(AlertRuleMetric::HorizonPaused)
        ->and($anomalies[$paused->id][0]['since']->equalTo($this->now->subMinutes(17)))->toBeTrue()
        ->and($anomalies[$paused->id][0]['truncated'])->toBeFalse()
        ->and($anomalies[$unreachable->id])->toHaveCount(1)
        ->and($anomalies[$unreachable->id][0]['metric'])->toBe(AlertRuleMetric::EndpointUnreachable)
        ->and($anomalies[$unreachable->id][0]['since']->equalTo($this->now->subMinutes(6)))->toBeTrue()
        ->and($anomalies[$inactive->id])->toHaveCount(1)
        ->and($anomalies[$inactive->id][0]['metric'])->toBe(AlertRuleMetric::HorizonMasterInactive)
        ->and($anomalies[$inactive->id][0]['since']->equalTo($this->now->subMinutes(8)))->toBeTrue()
        ->and($this->readings->openAnomalies([]))->toBe([]);
});

test('a state anomaly opens only once its run has lasted the minutes of its rule', function (EnvironmentStatus $status, AlertRuleMetric $metric, int $minutes) {
    $young = Environment::factory()->create();
    $due = Environment::factory()->create();
    $reading = fn (Environment $environment, CarbonImmutable $at) => EnvironmentSnapshot::factory()->for($environment)->create([
        'captured_at' => $at,
        'status' => $status,
        'error' => $status === EnvironmentStatus::Unreachable ? 'unreachable' : null,
        'breaches' => [$metric],
    ]);

    $reading($young, $this->now->subMinutes($minutes)->addSecond());
    $reading($young, $this->now->subSeconds(15));
    $reading($due, $this->now->subMinutes($minutes));
    $reading($due, $this->now->subSeconds(15));

    $anomalies = $this->readings->openAnomalies([$young, $due]);

    expect($anomalies)->not->toHaveKey($young->id)
        ->and($anomalies[$due->id])->toHaveCount(1)
        ->and($anomalies[$due->id][0]['metric'])->toBe($metric)
        ->and($anomalies[$due->id][0]['since']->equalTo($this->now->subMinutes($minutes)))->toBeTrue();
})->with([
    'unreachable' => [EnvironmentStatus::Unreachable, AlertRuleMetric::EndpointUnreachable, 2],
    'inactive' => [EnvironmentStatus::Inactive, AlertRuleMetric::HorizonMasterInactive, 5],
    'paused' => [EnvironmentStatus::Paused, AlertRuleMetric::HorizonPaused, 15],
]);

test('a state anomaly is measured up to now, not up to its latest reading', function () {
    $environment = Environment::factory()->create(['polling_enabled' => false]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $this->now->subMinutes(2)]);

    expect($this->readings->openAnomalies([$environment])[$environment->id][0]['metric'])
        ->toBe(AlertRuleMetric::EndpointUnreachable);
});

test('a state run truncated by the look-back is open', function () {
    $environment = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->create([
        'captured_at' => $this->now->subHours(30),
        'status' => EnvironmentStatus::Paused,
        'breaches' => [AlertRuleMetric::HorizonPaused],
    ]);
    EnvironmentSnapshot::factory()->for($environment)->create([
        'captured_at' => $this->now,
        'status' => EnvironmentStatus::Paused,
        'breaches' => [AlertRuleMetric::HorizonPaused],
    ]);

    $anomaly = $this->readings->openAnomalies([$environment])[$environment->id][0];

    expect($anomaly['metric'])->toBe(AlertRuleMetric::HorizonPaused)
        ->and($anomaly['truncated'])->toBeTrue();
});

test('the thresholds breached behind a young pause open at once', function () {
    $environment = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->create([
        'captured_at' => $this->now->subMinute(),
        'status' => EnvironmentStatus::Paused,
        'breaches' => [AlertRuleMetric::HorizonPaused, AlertRuleMetric::QueuePending],
    ]);

    expect(array_map(fn ($anomaly) => $anomaly['metric'], $this->readings->openAnomalies([$environment])[$environment->id]))
        ->toBe([AlertRuleMetric::QueuePending]);
});

test('the latest reading is found even when it is old, walking the composite index', function () {
    $environment = Environment::factory()->create(['polling_enabled' => false]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now->subDays(20), 'pending' => 3]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now->subDays(10), 'pending' => 8]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now->subDays(10), 'pending' => 9]);
    EnvironmentState::factory()->for($environment)->create(['captured_at' => $this->now->subDays(10)]);

    DB::enableQueryLog();
    $latest = $this->readings->latestFor([$environment]);
    $query = DB::getQueryLog()[0]['query'];

    expect($latest[$environment->id]->getAttribute('snapshot_pending'))->toBe(9)
        ->and($query)->toContain('(environment_snapshots.environment_id, environment_snapshots.captured_at) <=')
        ->and($query)->toContain('order by "environment_snapshots"."environment_id" desc, "environment_snapshots"."captured_at" desc');
});

test('the anomaly query is one bounded statement on the composite index', function () {
    $environments = Environment::factory()->count(3)->create();
    $environments->each(fn (Environment $environment) => EnvironmentSnapshot::factory()->for($environment)->failed()->count(2)->sequence(
        ['captured_at' => $this->now->subMinutes(3)],
        ['captured_at' => $this->now],
    )->create());

    DB::enableQueryLog();
    $anomalies = $this->readings->openAnomalies($environments->all());
    $log = DB::getQueryLog();

    expect($anomalies)->toHaveCount(3)
        ->and($log)->toHaveCount(1)
        ->and($log[0]['bindings'])->toBe(['{'.$environments->modelKeys()[0].','.$environments->modelKeys()[1].','.$environments->modelKeys()[2].'}'])
        ->and($log[0]['query'])->toContain('unnest(?::bigint[])')
        ->and($log[0]['query'])->toContain("interval '24 hours'")
        ->and(substr_count($log[0]['query'], "- interval '24 hours'"))->toBe(3)
        ->and(substr_count($log[0]['query'], ') <= ('))->toBe(2);
});

test('a run longer than the look-back is truncated at its edge', function () {
    $environment = Environment::factory()->create();

    foreach ([48, 25, 12, 0] as $hoursAgo) {
        EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait])->create(['captured_at' => $this->now->subHours($hoursAgo)]);
    }
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait])->create(['captured_at' => $this->now->subHours(24)]);

    $anomaly = $this->readings->openAnomalies([$environment])[$environment->id][0];

    expect($anomaly['truncated'])->toBeTrue()
        ->and($anomaly['since']->equalTo($this->now->subHours(24)))->toBeTrue();
});

test('a gap just past the look-back does not truncate the run', function () {
    $environment = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now->subHours(25)]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait])->create(['captured_at' => $this->now->subHours(23)]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait])->create(['captured_at' => $this->now]);

    $anomaly = $this->readings->openAnomalies([$environment])[$environment->id][0];

    expect($anomaly['truncated'])->toBeFalse()
        ->and($anomaly['since']->equalTo($this->now->subHours(23)))->toBeTrue();
});

test('readings in the same second are ordered by insertion in the gap search', function () {
    $environment = Environment::factory()->create();
    $second = $this->now->subMinutes(5);

    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now->subMinutes(10)]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $second]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $second]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now]);

    $tied = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($tied)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now->subMinutes(3)]);
    EnvironmentSnapshot::factory()->for($tied)->create(['captured_at' => $this->now]);
    EnvironmentSnapshot::factory()->for($tied)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now]);

    $anomalies = $this->readings->openAnomalies([$environment, $tied]);

    expect($anomalies[$environment->id][0]['since']->equalTo($second))->toBeTrue()
        ->and($anomalies[$tied->id][0]['since']->equalTo($this->now))->toBeTrue();
});

test('one unreachable reading in a long degradation restarts its "since"', function () {
    $environment = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now->subHours(5)]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $this->now->subMinutes(30)]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now->subMinutes(29)]);
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => $this->now]);

    expect($this->readings->openAnomalies([$environment])[$environment->id][0]['since']->equalTo($this->now->subMinutes(29)))->toBeTrue();
});

test('an environment polled less often than a bucket is carried over its empty buckets', function () {
    $slow = Environment::factory()->create(['poll_interval_seconds' => 300]);
    $fast = Environment::factory()->create(['poll_interval_seconds' => 15]);
    $last = $this->lastBucket;

    EnvironmentSnapshot::factory()->for($slow)->create(['captured_at' => $last->subSeconds(7 * 225), 'jobs_per_minute' => 10, 'max_wait_seconds' => 6]);
    EnvironmentSnapshot::factory()->for($slow)->create(['captured_at' => $last->subSeconds(5 * 225), 'jobs_per_minute' => 20, 'max_wait_seconds' => 8]);
    EnvironmentSnapshot::factory()->for($slow)->create(['captured_at' => $last, 'jobs_per_minute' => 30, 'max_wait_seconds' => 9]);
    EnvironmentSnapshot::factory()->for($fast)->create(['captured_at' => $last->subSeconds(6 * 225), 'jobs_per_minute' => 1]);

    $throughput = $this->readings->throughputSeries([$slow->id, $fast->id], SeriesRange::ThreeHours);
    $maxWait = $this->readings->maxWaitSeries($slow->id, SeriesRange::ThreeHours);

    expect(array_slice($throughput, 38))->toBe([0, 0, 10, 11, 20, 20, 0, 0, 0, 30])
        ->and(array_slice($maxWait, 38))->toBe([0, 0, 6, 6, 8, 8, 0, 0, 0, 9]);
});

test('the carry reaches as far as the tick-rounded interval and its slack', function () {
    $environment = Environment::factory()->create(['poll_interval_seconds' => 211]);
    $bucket = fn (int $index) => $this->lastBucket->subSeconds((47 - $index) * 225);

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $bucket(40)->addSeconds(224), 'jobs_per_minute' => 10]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $bucket(40)->addSeconds(224 + 240), 'jobs_per_minute' => 20]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $bucket(45), 'jobs_per_minute' => 30]);

    expect(array_slice($this->readings->throughputSeries([$environment->id], SeriesRange::ThreeHours), 39))
        ->toBe([0, 10, 10, 20, 20, 0, 30, 30, 0]);
});

test('a bucket whose only readings failed is never filled, and stops the carry', function () {
    $environment = Environment::factory()->create(['poll_interval_seconds' => 600]);
    $bucket = fn (int $index) => $this->lastBucket->subSeconds((47 - $index) * 225);

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $bucket(38), 'jobs_per_minute' => 10, 'max_wait_seconds' => 4]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $bucket(39)]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $bucket(42)]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $bucket(42)->addSeconds(10), 'jobs_per_minute' => 20, 'max_wait_seconds' => 7]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $bucket(44)]);

    expect(array_slice($this->readings->throughputSeries([$environment->id], SeriesRange::ThreeHours), 37))
        ->toBe([0, 10, 0, 0, 0, 20, 20, 0, 0, 0, 0])
        ->and(array_slice($this->readings->maxWaitSeries($environment->id, SeriesRange::ThreeHours), 37))
        ->toBe([0, 4, 0, 0, 0, 7, 7, 0, 0, 0, 0]);
});

test('critical anomalies come first', function () {
    $environment = Environment::factory()->create();

    foreach ([10, 0] as $minutesAgo) {
        EnvironmentSnapshot::factory()->for($environment)->create([
            'captured_at' => $this->now->subMinutes($minutesAgo),
            'status' => EnvironmentStatus::Inactive,
            'breaches' => [AlertRuleMetric::WorkersMissing, AlertRuleMetric::HorizonMasterInactive],
        ]);
    }

    expect(array_map(fn ($anomaly) => $anomaly['metric'], $this->readings->openAnomalies([$environment])[$environment->id]))
        ->toBe([AlertRuleMetric::HorizonMasterInactive, AlertRuleMetric::WorkersMissing]);
});

test('the pending trend averages each five-minute bucket of the last hour', function () {
    $environment = Environment::factory()->create();
    $other = Environment::factory()->create();
    $first = CarbonImmutable::parse('2026-09-17 09:10:00', 'UTC');
    $in = fn (int $bucket, int $seconds = 0) => $first->addMinutes(5 * $bucket)->addSeconds($seconds);

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $in(0), 'pending' => 4]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $first->subSecond(), 'pending' => 999]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $in(5, 10), 'pending' => 10]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $in(5, 200), 'pending' => 21]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $in(5, 100)]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => $in(7)]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now, 'pending' => 30]);
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $in(12), 'pending' => 999]);
    EnvironmentSnapshot::factory()->for($other)->create(['captured_at' => $in(3), 'pending' => 500]);

    $trends = $this->readings->pendingTrends([$environment]);

    expect(array_keys($trends))->toBe([$environment->id])
        ->and($trends[$environment->id]['points'])->toBe([4, 0, 0, 0, 0, 16, 0, 0, 0, 0, 0, 30])
        ->and($trends[$environment->id]['percent'])->toBeNull();
});

test('the trend variation compares the last three buckets with data to the three before', function (array $buckets, array $points, ?int $percent) {
    $environment = Environment::factory()->create();
    $first = CarbonImmutable::parse('2026-09-17 09:10:00', 'UTC');

    foreach ($buckets as $index => $readings) {
        foreach ((array) $readings as $offset => $pending) {
            EnvironmentSnapshot::factory()->for($environment)->create([
                'captured_at' => $first->addMinutes(5 * $index)->addSeconds(60 + $offset * 15),
                'pending' => $pending,
            ]);
        }
    }

    $trend = $this->readings->pendingTrends([$environment])[$environment->id];

    expect($trend['points'])->toBe($points)
        ->and($trend['percent'])->toBe($percent);
})->with([
    'growth' => [
        [null, null, null, null, null, null, 10, 10, 10, 20, 20, 20],
        [0, 0, 0, 0, 0, 0, 10, 10, 10, 20, 20, 20],
        100,
    ],
    'decline, across empty buckets' => [
        [40, null, 40, null, 40, null, 30, null, null, 30, 30, null],
        [40, 0, 40, 0, 40, 0, 30, 0, 0, 30, 30, 0],
        -25,
    ],
    'only the six latest buckets with data count' => [
        [1000, 1000, 10, 10, 10, 15, 15, 15, null, null, null, null],
        [1000, 1000, 10, 10, 10, 15, 15, 15, 0, 0, 0, 0],
        50,
    ],
    'an empty queue is data' => [
        [null, null, null, null, null, null, 30, 30, 30, 0, 0, 0],
        [0, 0, 0, 0, 0, 0, 30, 30, 30, 0, 0, 0],
        -100,
    ],
    'rounded down' => [
        [null, null, null, null, null, null, 3, 3, 3, 4, 4, 4],
        [0, 0, 0, 0, 0, 0, 3, 3, 3, 4, 4, 4],
        33,
    ],
    'rounded up' => [
        [null, null, null, null, null, null, 3, 3, 3, 5, 5, 5],
        [0, 0, 0, 0, 0, 0, 3, 3, 3, 5, 5, 5],
        67,
    ],
    'a bucket averaging to a half' => [
        [null, null, null, null, null, null, 10, 10, [10, 11], 11, 11, 11],
        [0, 0, 0, 0, 0, 0, 10, 10, 11, 11, 11, 11],
        8,
    ],
    'flat' => [
        array_fill(0, 12, 5),
        array_fill(0, 12, 5),
        0,
    ],
    'a base of zero' => [
        [null, null, null, null, null, null, 0, 0, 0, 5, 5, 5],
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 5, 5],
        null,
    ],
    'five buckets with data' => [
        [null, null, null, null, null, null, null, 1, 1, 1, 2, 2],
        [0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 2, 2],
        null,
    ],
    'no reading' => [
        array_fill(0, 12, null),
        array_fill(0, 12, 0),
        null,
    ],
]);

test('the trends of many environments are read in one query', function () {
    $environments = Environment::factory()->count(5)->create();
    $environments->each(fn (Environment $environment) => EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => $this->now, 'pending' => $environment->id]));

    DB::enableQueryLog();
    $trends = $this->readings->pendingTrends($environments);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and(array_keys($trends))->toBe($environments->modelKeys())
        ->and(array_map(fn (array $trend) => last($trend['points']), $trends))
        ->toBe(array_combine($environments->modelKeys(), $environments->modelKeys()));

    DB::flushQueryLog();

    expect($this->readings->pendingTrends([]))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});
