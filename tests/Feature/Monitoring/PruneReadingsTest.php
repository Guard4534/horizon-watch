<?php

use App\Enums\AlertRuleMetric;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\AlertNotification;
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
        if (str_starts_with(strtolower($query->sql), 'delete from "environment_snapshots"')) {
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

test('resolved alerts past the alert retention go, with their log, and everything else stays', function () {
    config(['horizon-watch.alert_retention_days' => 90]);
    $alertAt = fn (?string $resolvedAt) => Alert::factory()->for($this->environment)->create([
        'metric' => AlertRuleMetric::QueuePending,
        'opened_at' => '2026-01-01 00:00:00',
        'resolved_at' => $resolvedAt,
    ]);

    $old = $alertAt('2026-06-18 09:59:59');
    $kept = $alertAt('2026-06-19 10:00:00');
    $open = $alertAt(null);
    AlertNotification::factory()->for($old)->create();
    $keptLog = AlertNotification::factory()->for($kept)->create(['sent_at' => '2026-01-01 00:00:00']);
    $oldDigest = AlertNotification::factory()->create([
        'alert_id' => null,
        'team_id' => $old->team_id,
        'kind' => SentNotificationKind::WarningDigest,
        'sent_at' => '2026-06-18 09:59:59',
    ]);
    $recentTest = AlertNotification::factory()->create([
        'alert_id' => null,
        'team_id' => $old->team_id,
        'kind' => SentNotificationKind::Test,
        'sent_at' => '2026-06-19 10:00:00',
    ]);

    $this->artisan('monitoring:prune')
        ->expectsOutput('Deleted 0 readings.')
        ->expectsOutput('Deleted 1 alert.')
        ->expectsOutput('Deleted 1 notification.')
        ->assertSuccessful();

    expect(Alert::query()->pluck('id')->sort()->values()->all())->toBe(collect([$kept->id, $open->id])->sort()->values()->all())
        ->and(AlertNotification::query()->pluck('id')->sort()->values()->all())->toBe(collect([$keptLog->id, $recentTest->id])->sort()->values()->all())
        ->and(AlertNotification::query()->whereKey($oldDigest->id)->exists())->toBeFalse();
});

test('old alerts are deleted in chunks, and the alert retention is not the reading retention', function () {
    config(['horizon-watch.retention_days' => 1, 'horizon-watch.alert_retention_days' => 7]);
    $environments = Environment::factory()->count(5)->sequence(fn ($sequence) => ['name' => "worker-{$sequence->index}"])->create();
    $environments->each(fn (Environment $environment) => Alert::factory()->for($environment)->create(['resolved_at' => '2026-09-09 10:00:00']));
    $recent = Alert::factory()->for($this->environment)->create(['resolved_at' => '2026-09-11 10:00:00']);

    $deletes = 0;
    DB::listen(function (QueryExecuted $query) use (&$deletes) {
        if (str_starts_with(strtolower($query->sql), 'delete from "alerts"')) {
            $deletes++;
        }
    });

    $this->artisan('monitoring:prune', ['--chunk' => 2])
        ->expectsOutput('Deleted 5 alerts.')
        ->expectsOutput('Deleted 0 notifications.')
        ->assertSuccessful();

    expect($deletes)->toBe(4)
        ->and(Alert::query()->pluck('id')->all())->toBe([$recent->id]);
});
