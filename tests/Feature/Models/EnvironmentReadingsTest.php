<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

test('a new environment is polled by default and has never been polled', function () {
    $environment = Environment::factory()->create();

    expect($environment->polling_enabled)->toBeTrue()
        ->and($environment->fresh()->polling_enabled)->toBeTrue()
        ->and($environment->last_polled_at)->toBeNull()
        ->and($environment->next_poll_at)->toBeNull();
});

test('polling can be switched off by mass assignment and the poll times are immutable dates', function () {
    $environment = Environment::factory()->create();

    $environment->update(['polling_enabled' => false]);
    $environment->forceFill([
        'last_polled_at' => '2026-09-17 10:00:00',
        'next_poll_at' => '2026-09-17 10:00:15',
    ])->save();

    $fresh = $environment->fresh();

    expect($fresh->polling_enabled)->toBeFalse()
        ->and($fresh->last_polled_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->next_poll_at?->toDateTimeString())->toBe('2026-09-17 10:00:15');
});

test('the factory points at the dashboard, not at the api', function () {
    expect(Environment::factory()->make()->horizon_url)->toEndWith('/horizon');
});

test('snapshots belong to their environment and cast their columns', function () {
    $environment = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->count(2)->create();
    EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueuePending, AlertRuleMetric::JobRuntime])->create();
    EnvironmentSnapshot::factory()->for($environment)->failed(ReadingError::Unauthorized)->create();
    EnvironmentSnapshot::factory()->create();

    $snapshots = $environment->snapshots()->orderBy('id')->get();
    $healthy = $snapshots[0];
    $degraded = $snapshots[2];
    $failed = $snapshots[3];

    expect($snapshots)->toHaveCount(4)
        ->and($healthy->environment->is($environment))->toBeTrue()
        ->and($healthy->captured_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($healthy->status)->toBe(EnvironmentStatus::Active)
        ->and($healthy->error)->toBeNull()
        ->and($healthy->breaches)->toBeInstanceOf(Collection::class)
        ->and($healthy->breaches->all())->toBe([])
        ->and($degraded->status)->toBe(EnvironmentStatus::Degraded)
        ->and($degraded->breaches->all())->toBe([AlertRuleMetric::QueuePending, AlertRuleMetric::JobRuntime])
        ->and($failed->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($failed->error)->toBe(ReadingError::Unauthorized)
        ->and($failed->breaches->all())->toBe([AlertRuleMetric::EndpointUnreachable])
        ->and($failed->latency_ms)->toBeNull();
});

test('breaches are stored as metric values and default to an empty list', function () {
    $environment = Environment::factory()->create();
    $snapshot = EnvironmentSnapshot::factory()->for($environment)->degraded([AlertRuleMetric::QueueMaxWait])->create();

    DB::table('environment_snapshots')->insert([
        'environment_id' => $environment->id,
        'captured_at' => now(),
        'status' => 'active',
    ]);

    expect(json_decode((string) DB::table('environment_snapshots')->where('id', $snapshot->id)->value('breaches'), true))
        ->toBe(['queue.max_wait'])
        ->and(EnvironmentSnapshot::query()->latest('id')->first()?->breaches->all())->toBe([]);
});

test('the state belongs to its environment and casts its columns', function () {
    $environment = Environment::factory()->create();
    EnvironmentState::factory()->for($environment)->failed(ReadingError::NotHorizon)->create();

    $state = $environment->state;

    expect($state)->toBeInstanceOf(EnvironmentState::class)
        ->and($state->environment->is($environment))->toBeTrue()
        ->and($state->captured_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($state->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($state->error)->toBe(ReadingError::NotHorizon)
        ->and($state->nodes[0])->toHaveKeys(['hostname', 'status', 'workers', 'supervisors', 'queues'])
        ->and($state->queues[0])->toHaveKeys(['name', 'supervisor', 'workers', 'pending', 'waitSeconds', 'runtimeSeconds'])
        ->and($state->failed_jobs[0])->toHaveKeys(['job', 'queue', 'exception', 'tries', 'failedAt'])
        ->and($state->pending_jobs)->toBe([]);
});

test('an environment without readings has no state', function () {
    expect(Environment::factory()->create()->state)->toBeNull();
});

test('the relations eager-load', function () {
    $environments = Environment::factory()->count(2)->create();
    EnvironmentState::factory()->for($environments[0])->create();
    EnvironmentSnapshot::factory()->for($environments[0])->count(3)->create();

    $loaded = Environment::query()->with(['state', 'snapshots'])->orderBy('id')->get();

    expect($loaded[0]->state?->environment_id)->toBe($environments[0]->id)
        ->and($loaded[0]->snapshots)->toHaveCount(3)
        ->and($loaded[1]->state)->toBeNull()
        ->and($loaded[1]->snapshots)->toHaveCount(0);
});

test('an environment has at most one state', function () {
    $environment = Environment::factory()->create();
    EnvironmentState::factory()->for($environment)->create();

    expect(fn () => EnvironmentState::factory()->for($environment)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('deleting an environment deletes its snapshots and its state', function () {
    $environment = Environment::factory()->create();
    $other = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($environment)->count(3)->create();
    EnvironmentState::factory()->for($environment)->create();
    EnvironmentSnapshot::factory()->for($other)->create();
    EnvironmentState::factory()->for($other)->create();

    $environment->delete();

    expect(EnvironmentSnapshot::query()->where('environment_id', $environment->id)->exists())->toBeFalse()
        ->and(EnvironmentState::query()->where('environment_id', $environment->id)->exists())->toBeFalse()
        ->and(EnvironmentSnapshot::query()->where('environment_id', $other->id)->count())->toBe(1)
        ->and(EnvironmentState::query()->where('environment_id', $other->id)->count())->toBe(1);
});

test('a target is built from the environment with its decrypted password', function () {
    $environment = Environment::factory()->create([
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'correct-horse-battery',
    ]);

    $target = HorizonTarget::fromEnvironment($environment->fresh());

    expect($target->dashboardUrl)->toBe('https://shop.example.com/horizon')
        ->and($target->username)->toBe('monitor')
        ->and($target->password())->toBe('correct-horse-battery')
        ->and($target->hasBasicAuth())->toBeTrue();
});

test('a target without credentials sends no basic auth', function () {
    $environment = Environment::factory()->develop()->create();

    $target = HorizonTarget::fromEnvironment($environment);

    expect($target->username)->toBeNull()
        ->and($target->password())->toBeNull()
        ->and($target->hasBasicAuth())->toBeFalse();
});
