<?php

use App\Enums\EnvironmentStatus;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00'));

    $this->backfill = function (): void {
        $migration = require database_path('migrations/2026_09_18_000007_backfill_status_since_on_environment_states_table.php');
        $migration->up();
    };

    $this->stateWithoutStart = function (Environment $environment, EnvironmentStatus $status, string $capturedAt): EnvironmentState {
        $state = EnvironmentState::factory()->for($environment)->create(['status' => $status, 'captured_at' => $capturedAt]);
        DB::table('environment_states')->where('id', $state->id)->update(['status_since' => null]);

        return $state;
    };

    $this->snapshot = fn (Environment $environment, EnvironmentStatus $status, string $capturedAt) => EnvironmentSnapshot::factory()
        ->for($environment)
        ->create(['status' => $status, 'captured_at' => $capturedAt]);
});

test('a state without a start gets the first reading of its trailing run', function () {
    $environment = Environment::factory()->create();
    ($this->snapshot)($environment, EnvironmentStatus::Inactive, '2026-09-18 09:00:00');
    ($this->snapshot)($environment, EnvironmentStatus::Active, '2026-09-18 09:10:00');
    ($this->snapshot)($environment, EnvironmentStatus::Inactive, '2026-09-18 09:20:00');
    ($this->snapshot)($environment, EnvironmentStatus::Inactive, '2026-09-18 09:30:00');
    ($this->snapshot)($environment, EnvironmentStatus::Active, '2026-09-18 09:40:00');
    $state = ($this->stateWithoutStart)($environment, EnvironmentStatus::Inactive, '2026-09-18 09:30:00');

    ($this->backfill)();

    expect($state->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 09:20:00');
});

test('a run that covers every stored reading starts at the oldest one', function () {
    $environment = Environment::factory()->create();
    ($this->snapshot)($environment, EnvironmentStatus::Unreachable, '2026-09-18 08:00:00');
    ($this->snapshot)($environment, EnvironmentStatus::Unreachable, '2026-09-18 09:00:00');
    $state = ($this->stateWithoutStart)($environment, EnvironmentStatus::Unreachable, '2026-09-18 09:00:00');

    ($this->backfill)();

    expect($state->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 08:00:00');
});

test('a state whose readings were pruned starts at its own reading', function () {
    $environment = Environment::factory()->create();
    ($this->snapshot)($environment, EnvironmentStatus::Active, '2026-09-18 09:00:00');
    $state = ($this->stateWithoutStart)($environment, EnvironmentStatus::Paused, '2026-09-18 09:30:00');

    ($this->backfill)();

    expect($state->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 09:30:00');
});

test('a reading newer than the state never becomes its start', function () {
    $environment = Environment::factory()->create();
    ($this->snapshot)($environment, EnvironmentStatus::Active, '2026-09-18 09:00:00');
    ($this->snapshot)($environment, EnvironmentStatus::Paused, '2026-09-18 09:40:00');
    $state = ($this->stateWithoutStart)($environment, EnvironmentStatus::Paused, '2026-09-18 09:30:00');

    ($this->backfill)();

    expect($state->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 09:30:00');
});

test('a start already known is kept, and other environments do not interfere', function () {
    $environment = Environment::factory()->create();
    $other = Environment::factory()->create();
    ($this->snapshot)($other, EnvironmentStatus::Paused, '2026-09-18 07:00:00');
    ($this->snapshot)($environment, EnvironmentStatus::Paused, '2026-09-18 09:00:00');
    $known = EnvironmentState::factory()->for($other)->create([
        'status' => EnvironmentStatus::Paused,
        'captured_at' => '2026-09-18 09:30:00',
        'status_since' => '2026-09-18 09:15:00',
    ]);
    $state = ($this->stateWithoutStart)($environment, EnvironmentStatus::Paused, '2026-09-18 09:30:00');

    ($this->backfill)();

    expect($state->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 09:00:00')
        ->and($known->fresh()->status_since->toDateTimeString())->toBe('2026-09-18 09:15:00');
});

test('a factory state starts its run at its own reading', function () {
    $state = EnvironmentState::factory()->create(['captured_at' => '2026-09-18 09:45:00'])->fresh();

    expect($state->status_since->toDateTimeString())->toBe('2026-09-18 09:45:00');
});
