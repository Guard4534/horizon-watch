<?php

use App\Enums\EnvironmentStatus;
use App\Models\EnvironmentState;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->backfill = function (): void {
        $migration = require database_path('migrations/2026_10_13_080002_backfill_last_known_status_on_environment_states_table.php');
        $migration->up();
    };

    $this->stateWithoutKnown = function (EnvironmentState $state): EnvironmentState {
        DB::table('environment_states')->where('id', $state->id)->update([
            'last_known_status' => null,
            'last_known_since' => null,
            'last_known_at' => null,
        ]);

        return $state;
    };
});

test('a state read successfully remembers its own status, start and time', function () {
    $state = ($this->stateWithoutKnown)(EnvironmentState::factory()->create([
        'status' => EnvironmentStatus::Inactive,
        'captured_at' => '2026-10-13 09:30:00',
        'status_since' => '2026-10-13 09:10:00',
    ]));

    ($this->backfill)();

    $state = $state->fresh();

    expect($state->last_known_status)->toBe(EnvironmentStatus::Inactive)
        ->and($state->last_known_since->toDateTimeString())->toBe('2026-10-13 09:10:00')
        ->and($state->last_known_at->toDateTimeString())->toBe('2026-10-13 09:30:00');
});

test('an unreachable state knows no status before it', function () {
    $state = ($this->stateWithoutKnown)(EnvironmentState::factory()->failed()->create());

    ($this->backfill)();

    expect($state->fresh()->last_known_status)->toBeNull();
});

test('a factory state remembers its own reading', function () {
    $state = EnvironmentState::factory()->create([
        'status' => EnvironmentStatus::Paused,
        'captured_at' => '2026-10-13 09:45:00',
        'status_since' => '2026-10-13 09:15:00',
    ])->fresh();
    $failed = EnvironmentState::factory()->failed()->create()->fresh();

    expect($state->last_known_status)->toBe(EnvironmentStatus::Paused)
        ->and($state->last_known_since->toDateTimeString())->toBe('2026-10-13 09:15:00')
        ->and($state->last_known_at->toDateTimeString())->toBe('2026-10-13 09:45:00')
        ->and($failed->last_known_status)->toBeNull();
});
