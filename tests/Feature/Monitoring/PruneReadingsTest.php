<?php

use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));

    config(['horizon-watch.retention_days' => 30]);

    $this->environment = Environment::factory()->create();

    $this->snapshotAt = fn (string $capturedAt, ?Environment $environment = null): EnvironmentSnapshot => EnvironmentSnapshot::factory()
        ->for($environment ?? $this->environment)
        ->create(['captured_at' => $capturedAt]);
});

test('readings older than the retention are deleted and newer ones kept', function () {
    $other = Environment::factory()->create();

    $kept = [
        ($this->snapshotAt)('2026-08-19 10:00:00'),
        ($this->snapshotAt)('2026-09-17 09:59:45'),
        ($this->snapshotAt)('2026-08-19 10:00:00', $other),
    ];
    ($this->snapshotAt)('2026-08-17 10:00:00');
    ($this->snapshotAt)('2026-08-17 10:00:00', $other);
    ($this->snapshotAt)('2026-01-01 00:00:00');
    EnvironmentState::factory()->for($this->environment)->create(['captured_at' => '2026-08-01 00:00:00']);

    $this->artisan('monitoring:prune')
        ->expectsOutput('Deleted 3 readings.')
        ->assertSuccessful();

    expect(EnvironmentSnapshot::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect($kept)->pluck('id')->sort()->values()->all())
        ->and(EnvironmentState::query()->count())->toBe(1);
});

test('the retention period comes from the configuration', function () {
    config(['horizon-watch.retention_days' => 7]);

    $kept = ($this->snapshotAt)('2026-09-11 10:00:00');
    ($this->snapshotAt)('2026-09-09 10:00:00');

    $this->artisan('monitoring:prune')->expectsOutput('Deleted 1 reading.')->assertSuccessful();

    expect(EnvironmentSnapshot::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('old readings are deleted in chunks until none is left', function () {
    foreach (range(1, 5) as $day) {
        ($this->snapshotAt)(CarbonImmutable::parse('2026-08-10 10:00:00')->subDays($day)->toDateTimeString());
    }
    $kept = ($this->snapshotAt)('2026-09-01 10:00:00');

    $deletes = 0;
    DB::listen(function (QueryExecuted $query) use (&$deletes) {
        if (str_starts_with(strtolower($query->sql), 'delete')) {
            $deletes++;
        }
    });

    $this->artisan('monitoring:prune', ['--chunk' => 2])
        ->expectsOutput('Deleted 5 readings.')
        ->assertSuccessful();

    expect($deletes)->toBe(4)
        ->and(EnvironmentSnapshot::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('with nothing to delete it says so', function () {
    ($this->snapshotAt)('2026-09-17 09:00:00');

    $this->artisan('monitoring:prune')->expectsOutput('Deleted 0 readings.')->assertSuccessful();

    expect(EnvironmentSnapshot::query()->count())->toBe(1);
});
