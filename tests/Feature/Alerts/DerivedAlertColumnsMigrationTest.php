<?php

use App\Enums\AlertRuleMetric;
use App\Models\Alert;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    $this->migration = require database_path('migrations/2026_10_16_000004_drop_derived_columns_from_alerts_table.php');
    $this->environment = Environment::factory()->production()->create();
    $this->window = MaintenanceWindow::factory()->forEnvironment($this->environment)->create();
});

test('an alert row keeps neither a notified flag nor a unit', function () {
    expect(Schema::hasColumn('alerts', 'notified'))->toBeFalse()
        ->and(Schema::hasColumn('alerts', 'unit'))->toBeFalse();
});

test('upgrading keeps an alert notified even when its notice time was never written', function () {
    $lost = Alert::factory()->for($this->environment)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'opened_at' => now()->subMinutes(40),
        'maintenance_window_id' => $this->window->id,
    ]);
    $quiet = Alert::factory()->for($this->environment)->create(['metric' => AlertRuleMetric::JobRuntime]);

    $this->migration->down();
    DB::table('alerts')->where('id', $lost->id)->update(['notified' => true, 'last_notified_at' => null]);

    $this->migration->up();

    expect($lost->fresh()->wasNotified())->toBeTrue()
        ->and($lost->fresh()->last_notified_at->equalTo(now()->subMinutes(40)))->toBeTrue()
        ->and($quiet->fresh()->wasNotified())->toBeFalse();
});

test('downgrading restores the notified flag and the unit from what each alert still knows', function () {
    $told = Alert::factory()->for($this->environment)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'last_notified_at' => now()->subMinutes(5),
        'maintenance_window_id' => $this->window->id,
    ]);
    $pending = Alert::factory()->for($this->environment)->create(['metric' => AlertRuleMetric::QueuePending]);
    $runtime = Alert::factory()->for($this->environment)->create(['metric' => AlertRuleMetric::JobRuntime]);
    $workers = Alert::factory()->for($this->environment)->create(['metric' => AlertRuleMetric::WorkersMissing]);

    $this->migration->down();

    $rows = DB::table('alerts')->get()->keyBy('id');

    expect([$rows[$told->id]->notified, $rows[$told->id]->unit])->toBe([true, 'min'])
        ->and([$rows[$pending->id]->notified, $rows[$pending->id]->unit])->toBe([false, 'job'])
        ->and([$rows[$runtime->id]->notified, $rows[$runtime->id]->unit])->toBe([false, 's'])
        ->and([$rows[$workers->id]->notified, $rows[$workers->id]->unit])->toBe([false, '']);

    $this->migration->up();

    expect(Schema::hasColumn('alerts', 'notified'))->toBeFalse();
});
