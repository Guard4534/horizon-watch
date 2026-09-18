<?php

use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\AlertRuleMetric;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\SyntheticReadings;
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

test('the demo organization carries alerts that match its readings, some muted, one handled, and a recent history', function () {
    config()->set('horizon-watch.demo_horizon_url', null);
    $dispatched = 0;
    Event::listen([AlertOpened::class, AlertResolved::class], function () use (&$dispatched) {
        $dispatched++;
    });

    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->sole();
    $open = Alert::query()->open()->with('environment.state')->get();
    $resolved = Alert::query()->whereNotNull('resolved_at')->get();
    $latestBreaches = fn (Environment $environment) => $environment->snapshots()->orderByDesc('captured_at')->first()->breaches;

    expect($dispatched)->toBe(0)
        ->and($open->pluck('environment.slug')->unique()->sort()->values()->all())
        ->toBe(collect(array_keys(SyntheticReadings::INCIDENTS))->sort()->values()->all())
        ->and($open->every(fn (Alert $alert) => $alert->metric->isStateRule()
            ? $alert->environment->state->status->value === match ($alert->metric) {
                AlertRuleMetric::EndpointUnreachable => 'unreachable',
                AlertRuleMetric::HorizonMasterInactive => 'inactive',
                default => 'paused',
            }
            : $latestBreaches($alert->environment)->contains($alert->metric)))->toBeTrue()
        ->and($open->every(fn (Alert $alert) => $alert->opened_at->lt(now()) && $alert->last_seen_at->equalTo(now())))->toBeTrue()
        ->and($open->every(fn (Alert $alert) => $alert->team_id === $admin->current_team_id))->toBeTrue()
        ->and($open->where('muted_indefinitely', true))->toHaveCount(1)
        ->and($open->filter(fn (Alert $alert) => $alert->muted_until?->gt(now()) === true))->toHaveCount(1)
        ->and($open->whereNotNull('muted_until')->first()->muted_until->lte(now()->addHours(4)))->toBeTrue()
        ->and($open->whereNotNull('muted_by')->pluck('muted_by')->unique()->all())->toBe([$admin->id])
        ->and($open->whereNotNull('handled_at'))->toHaveCount(1)
        ->and($open->whereNotNull('handled_at')->first()->handled_by)->toBe($admin->id)
        ->and($open->whereNotNull('handled_at')->first()->muted_by)->toBeNull()
        ->and($resolved->count())->toBeGreaterThanOrEqual(10)
        ->and($resolved->every(fn (Alert $alert) => $alert->resolved_at->gte(now()->subHours(48))
            && $alert->resolved_at->lte(now())
            && $alert->opened_at->lt($alert->resolved_at)))->toBeTrue()
        ->and($resolved->every(fn (Alert $alert) => $alert->notified === ($alert->resolution_notified_at !== null)))->toBeTrue()
        ->and(AlertNotification::query()->where('target', 'like', '%://%')->exists())->toBeFalse();
});
