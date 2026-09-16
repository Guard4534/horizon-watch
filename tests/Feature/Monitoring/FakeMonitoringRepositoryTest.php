<?php

use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\EnvironmentStatus;
use App\Enums\RuleOrigin;
use App\Enums\SeriesRange;
use App\Models\Team;
use App\Monitoring\FakeMonitoringRepository;
use App\Monitoring\MonitoringRepository;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // A timestamp on a 15-second boundary, so "+14s" stays in the same tick.
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_020));
    $this->team = Team::factory()->make();
    $this->monitoring = app(MonitoringRepository::class);
    $this->snapshot = fn () => array_map(
        fn ($environment) => $environment->toArray(),
        $this->monitoring->environments($this->team),
    );
    $this->longRunningJobsSnapshot = fn () => array_map(
        fn ($job) => $job->toArray(),
        $this->monitoring->longRunningJobs($this->team, 'fatturaomatic-production'),
    );
});

test('the container resolves the fake repository', function () {
    expect($this->monitoring)->toBeInstanceOf(FakeMonitoringRepository::class);
});

test('it describes nine applications and twenty-nine environments', function () {
    expect($this->monitoring->applications($this->team))->toHaveCount(9)
        ->and($this->monitoring->environments($this->team))->toHaveCount(29);
});

test('the numbers hold still within one polling interval', function () {
    $first = ($this->snapshot)();

    $this->travel(14)->seconds();

    expect(($this->snapshot)())->toBe($first);
});

test('the numbers move on the next polling interval', function () {
    $first = ($this->snapshot)();

    $this->travel(15)->seconds();

    expect(($this->snapshot)())->not->toBe($first);
});

test('long-running jobs hold still within one polling interval', function () {
    $first = ($this->longRunningJobsSnapshot)();

    $this->travel(14)->seconds();

    expect(($this->longRunningJobsSnapshot)())->toBe($first);
});

test('long-running jobs move on the next polling interval', function () {
    // elapsedSeconds for this repository's scripted jobs does not depend on
    // the tick, so only startedAt (formatted "H:i") can show the move — pick
    // a boundary that also crosses a minute, or the assertion below would be
    // comparing two identical-looking timestamps.
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_005));

    $first = ($this->longRunningJobsSnapshot)();

    $this->travel(15)->seconds();

    expect(($this->longRunningJobsSnapshot)())->not->toBe($first);
});

test('the scripted incidents are always there', function () {
    $status = fn (string $id) => $this->monitoring->environment($this->team, $id)?->status;

    expect($status('fatturaomatic-production'))->toBe(EnvironmentStatus::Inactive)
        ->and($status('logistics-hub-worker-batch'))->toBe(EnvironmentStatus::Unreachable)
        ->and($status('acme-shop-staging'))->toBe(EnvironmentStatus::Paused)
        ->and($status('mailer-service-worker-batch'))->toBe(EnvironmentStatus::Degraded);
});

test('a down environment has no workers and no throughput', function () {
    $environment = $this->monitoring->environment($this->team, 'fatturaomatic-production');

    expect($environment->workers)->toBe(0)
        ->and($environment->jobsPerMinute)->toBe(0)
        ->and($environment->nodeCount)->toBe(3)
        ->and($this->monitoring->nodes($this->team, 'fatturaomatic-production'))->toHaveCount(3);
});

test('every unhealthy environment has exactly one open alert', function () {
    $unhealthy = array_filter(
        $this->monitoring->environments($this->team),
        fn ($environment) => ! $environment->status->isHealthy(),
    );
    $alerts = $this->monitoring->alerts($this->team, AlertState::Open);

    expect($alerts)->toHaveCount(count($unhealthy));

    foreach ($alerts as $alert) {
        expect($alert->severity)->toBe(
            $alert->environmentStatus->isDown() ? AlertSeverity::Critical : AlertSeverity::Warning,
        );
    }
});

test('overrides exist only on scopes that define them', function () {
    $overrides = fn (string $scope) => count(array_filter(
        $this->monitoring->alertRules($this->team, $scope),
        fn ($rule) => $rule->origin === RuleOrigin::Override,
    ));

    expect($this->monitoring->alertRules($this->team, 'organization'))->toHaveCount(8)
        ->and($overrides('organization'))->toBe(0)
        ->and($overrides('production'))->toBe(3)
        ->and($overrides('worker-batch'))->toBe(2)
        ->and($this->monitoring->alertRules($this->team, 'nope'))->toBe([]);
});

test('an unknown environment yields nothing', function () {
    expect($this->monitoring->environment($this->team, 'nope'))->toBeNull()
        ->and($this->monitoring->nodes($this->team, 'nope'))->toBe([])
        ->and($this->monitoring->queues($this->team, 'nope'))->toBe([]);
});

test('series have forty-eight points', function () {
    expect($this->monitoring->throughputSeries($this->team, null, SeriesRange::ThreeHours))->toHaveCount(48)
        ->and($this->monitoring->maxWaitSeries($this->team, 'acme-shop-production', SeriesRange::Week))->toHaveCount(48);
});
