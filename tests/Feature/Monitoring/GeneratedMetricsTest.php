<?php

use App\Enums\EnvironmentStatus;
use App\Enums\SeriesRange;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Monitoring\GeneratedMetrics;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // A timestamp on a 15-second boundary, so "+14s" stays in the same tick.
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_020));
    $this->metrics = new GeneratedMetrics;
    $team = Team::factory()->create();
    // Same name as the phase 1 fixture, so the slug matches a fixed incident.
    $this->application = Application::factory()->for($team)->create(['name' => 'Fatturaomatic']);
    $this->environment = Environment::factory()->for($this->application)->production()->create()->fresh(['application']);
});

test('the numbers hold still within one polling interval', function () {
    $first = $this->metrics->metricsFor($this->environment);

    $this->travel(14)->seconds();

    expect($this->metrics->metricsFor($this->environment))->toEqual($first);
});

test('the numbers move on the next polling interval', function () {
    $first = $this->metrics->metricsFor($this->environment);

    $this->travel(15)->seconds();

    expect($this->metrics->metricsFor($this->environment))->not->toEqual($first);
});

test('long-running jobs hold still within one polling interval', function () {
    $snapshot = fn () => array_map(
        fn ($job) => $job->toArray(),
        $this->metrics->longRunningJobs($this->environment, $this->metrics->metricsFor($this->environment)),
    );
    $first = $snapshot();

    $this->travel(14)->seconds();

    expect($snapshot())->toBe($first);
});

test('long-running jobs move on the next polling interval', function () {
    // elapsedSeconds for the scripted jobs does not depend on the tick, so
    // only startedAt (formatted "H:i") can show the move — pick a boundary
    // that also crosses a minute, or the assertion below would compare two
    // identical-looking timestamps.
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_005));
    $snapshot = fn () => array_map(
        fn ($job) => $job->toArray(),
        $this->metrics->longRunningJobs($this->environment, $this->metrics->metricsFor($this->environment)),
    );
    $first = $snapshot();

    $this->travel(15)->seconds();

    expect($snapshot())->not->toBe($first);
});

test('the scripted incidents are always there, keyed by slug', function () {
    $mailerService = Application::factory()->for($this->application->team)->create(['name' => 'Mailer Service']);
    $logisticsHub = Application::factory()->for($this->application->team)->create(['name' => 'Logistics Hub']);
    $acmeShop = Application::factory()->for($this->application->team)->create(['name' => 'Acme Shop']);

    $workerBatch = Environment::factory()->for($mailerService)->workerBatch()->create()->fresh(['application']);
    $unreachable = Environment::factory()->for($logisticsHub)->workerBatch()->create()->fresh(['application']);
    $paused = Environment::factory()->for($acmeShop)->staging()->create()->fresh(['application']);

    expect($this->environment->slug)->toBe('fatturaomatic-production')
        ->and($this->metrics->metricsFor($this->environment)->status)->toBe(EnvironmentStatus::Inactive)
        ->and($workerBatch->slug)->toBe('mailer-service-worker-batch')
        ->and($this->metrics->metricsFor($workerBatch)->status)->toBe(EnvironmentStatus::Degraded)
        ->and($unreachable->slug)->toBe('logistics-hub-worker-batch')
        ->and($this->metrics->metricsFor($unreachable)->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($paused->slug)->toBe('acme-shop-staging')
        ->and($this->metrics->metricsFor($paused)->status)->toBe(EnvironmentStatus::Paused);
});

test('an unknown slug is not treated as an incident', function () {
    $other = Environment::factory()->for($this->application)->staging()->create()->fresh(['application']);

    expect($other->slug)->not->toBe('fatturaomatic-production')
        ->and($this->metrics->metricsFor($other)->status)->not->toBe(EnvironmentStatus::Inactive);
});

test('a down environment has no workers, no throughput, and its incident-sized node list', function () {
    $metrics = $this->metrics->metricsFor($this->environment);

    expect($metrics->workers)->toBe(0)
        ->and($metrics->jobsPerMinute)->toBe(0)
        ->and($metrics->nodeCount)->toBe(3)
        ->and($this->metrics->nodes($this->environment, $metrics))->toHaveCount(3);
});

test('series have forty-eight points', function () {
    expect($this->metrics->throughputSeries(null, SeriesRange::ThreeHours))->toHaveCount(48)
        ->and($this->metrics->maxWaitSeries($this->environment->slug, false, SeriesRange::Week))->toHaveCount(48);
});
