<?php

use App\Models\Environment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
});

test('the demo environments are not polled and carry a day of readings', function () {
    config()->set('horizon-watch.demo_horizon_url', null);

    $this->seed(DatabaseSeeder::class);

    $environments = Environment::query()->with('state')->withCount('snapshots')->get();
    $dayAgo = now()->subDay();

    expect($environments)->toHaveCount(29)
        ->and($environments->every(fn (Environment $environment) => $environment->polling_enabled === false))->toBeTrue()
        ->and($environments->every(fn (Environment $environment) => $environment->state?->captured_at->equalTo(now())))->toBeTrue()
        ->and($environments->every(fn (Environment $environment) => $environment->snapshots_count === 480))->toBeTrue()
        ->and($environments->every(
            fn (Environment $environment) => $environment->snapshots()->where('captured_at', '<=', $dayAgo)->doesntExist(),
        ))->toBeTrue()
        ->and($environments->every(
            fn (Environment $environment) => $environment->snapshots()->distinct()->pluck('status')->all() === [$environment->state->status],
        ))->toBeTrue();
});

test('the demo environments are created already paused, never switched off afterwards', function () {
    config()->set('horizon-watch.demo_horizon_url', null);
    $pollingAtCreation = [];
    Event::listen('eloquent.created: '.Environment::class, function (Environment $environment) use (&$pollingAtCreation) {
        $pollingAtCreation[] = $environment->polling_enabled;
    });

    $this->seed(DatabaseSeeder::class);

    expect($pollingAtCreation)->toHaveCount(29)
        ->and(collect($pollingAtCreation)->every(fn ($polling) => $polling === false))->toBeTrue()
        ->and(Environment::query()->where('polling_enabled', true)->exists())->toBeFalse();
});

test('the monitoring fixture keeps polling on by default', function () {
    $team = Team::factory()->create();

    $environments = (new DatabaseSeeder)->seedMockupOrganization($team);

    expect($environments)->toHaveCount(29)
        ->and(collect($environments)->every(fn (Environment $environment) => $environment->fresh()->polling_enabled))->toBeTrue();
});

test('a configured local horizon is added, polled, and left without readings', function () {
    config()->set('horizon-watch.demo_horizon_url', 'http://host.docker.internal:8080/horizon');

    $this->seed(DatabaseSeeder::class);

    $local = Environment::query()->where('slug', 'local-horizon-local')->sole();

    expect(Environment::query()->count())->toBe(30)
        ->and(Environment::query()->where('polling_enabled', true)->count())->toBe(1)
        ->and($local->polling_enabled)->toBeTrue()
        ->and($local->horizon_url)->toBe('http://host.docker.internal:8080/horizon')
        ->and($local->poll_interval_seconds)->toBe(15)
        ->and($local->application->name)->toBe('Local Horizon')
        ->and($local->application->host)->toBe('localhost')
        ->and($local->application->team_id)->toBe(Environment::query()->where('slug', 'fatturaomatic-production')->sole()->team_id)
        ->and($local->state()->exists())->toBeFalse()
        ->and($local->snapshots()->exists())->toBeFalse();
});
