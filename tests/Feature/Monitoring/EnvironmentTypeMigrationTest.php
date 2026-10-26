<?php

use App\Enums\EnvironmentType;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->environments = require database_path('migrations/2026_10_16_000001_replace_color_and_production_flag_with_type_on_environments_table.php');
    $this->alerts = require database_path('migrations/2026_10_16_000002_rename_environment_color_to_environment_type_on_alerts_table.php');
    $this->hosts = require database_path('migrations/2026_10_16_000003_drop_host_from_applications_table.php');

    $this->rollBack = function (): void {
        $this->hosts->down();
        $this->alerts->down();
        $this->environments->down();
    };

    $this->migrate = function (): void {
        $this->environments->up();
        $this->alerts->up();
        $this->hosts->up();
    };

    $this->application = Application::factory()->create(['name' => 'Billing']);

    $this->oldEnvironment = fn (string $name, string $color, bool $production): int => DB::table('environments')->insertGetId([
        'application_id' => $this->application->id,
        'team_id' => $this->application->team_id,
        'name' => $name,
        'slug' => 'billing-'.$name,
        'color' => $color,
        'is_production' => $production,
        'horizon_url' => "https://{$name}.billing.example.com/horizon",
        'poll_interval_seconds' => 15,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->oldAlert = function (string $color, ?int $environmentId = null): string {
        $id = (string) Str::uuid();

        DB::table('alerts')->insert([
            'id' => $id,
            'team_id' => $this->application->team_id,
            'environment_id' => $environmentId,
            'metric' => 'queue.pending',
            'severity' => 'warning',
            'application_name' => 'Billing',
            'environment_name' => 'old',
            'environment_color' => $color,
            'threshold' => 1000,
            'opened_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    };
});

test('every old colour and production flag becomes one type', function () {
    ($this->rollBack)();

    $expected = [];

    foreach ([
        'prod' => 'production',
        'preprod' => 'preprod',
        'staging' => 'staging',
        'develop' => 'development',
        'demo' => 'demo',
        'testing' => 'testing',
        'worker' => 'staging',
        'rainbow' => 'staging',
    ] as $color => $type) {
        $expected[($this->oldEnvironment)("{$color}-plain", $color, false)] = $type;
        $expected[($this->oldEnvironment)("{$color}-flagged", $color, true)] = 'production';
    }

    ($this->migrate)();

    expect(DB::table('environments')->whereIn('id', array_keys($expected))->pluck('type', 'id')->all())->toEqual($expected)
        ->and(Schema::hasColumn('environments', 'color'))->toBeFalse()
        ->and(Schema::hasColumn('environments', 'is_production'))->toBeFalse()
        ->and(Schema::hasColumn('applications', 'host'))->toBeFalse();
});

test('an upgrade from 0.1.0 makes production what its colour or its name says', function () {
    ($this->rollBack)();

    $blue = ($this->oldEnvironment)('blue', 'prod', false);
    $prodEu = ($this->oldEnvironment)('prod-eu', 'staging', false);
    $qa = ($this->oldEnvironment)('qa', 'staging', false);

    (require database_path('migrations/2026_10_13_040002_backfill_is_production_on_environments_table.php'))->up();
    ($this->migrate)();

    expect(DB::table('environments')->whereIn('id', [$blue, $prodEu, $qa])->pluck('type', 'id')->all())->toEqual([
        $blue => 'production',
        $prodEu => 'production',
        $qa => 'staging',
    ]);
});

test('an environment flagged production but coloured worker stays hidden from members who see everything except production', function () {
    ($this->rollBack)();

    $flagged = ($this->oldEnvironment)('batch', 'worker', true);
    $plain = ($this->oldEnvironment)('worker-batch', 'worker', false);

    ($this->migrate)();

    $member = User::factory()->create();
    $this->application->team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $visible = app(VisibleEnvironments::class);

    expect($visible->sees($this->application->team, $member, $flagged))->toBeFalse()
        ->and($visible->sees($this->application->team, $member, $plain))->toBeTrue();
});

test('alerts stored with any old colour still cast to a type', function () {
    ($this->rollBack)();

    $expected = [];

    foreach ([
        'prod' => EnvironmentType::Production,
        'preprod' => EnvironmentType::Preprod,
        'staging' => EnvironmentType::Staging,
        'develop' => EnvironmentType::Development,
        'demo' => EnvironmentType::Demo,
        'testing' => EnvironmentType::Testing,
        'worker' => EnvironmentType::Staging,
        'rainbow' => EnvironmentType::Staging,
    ] as $color => $type) {
        $expected[($this->oldAlert)($color)] = $type;
    }

    ($this->migrate)();

    foreach ($expected as $id => $type) {
        expect(Alert::query()->findOrFail($id)->environment_type)->toBe($type);
    }

    expect(Schema::hasColumn('alerts', 'environment_color'))->toBeFalse();
});

test('alerts of an environment flagged production become production, whatever their colour', function () {
    ($this->rollBack)();

    $flagged = ($this->oldEnvironment)('batch', 'staging', true);
    $plain = ($this->oldEnvironment)('worker-batch', 'worker', false);

    $flaggedAlert = ($this->oldAlert)('staging', $flagged);
    $plainAlert = ($this->oldAlert)('worker', $plain);
    $orphanAlert = ($this->oldAlert)('staging');

    ($this->migrate)();

    expect(Alert::query()->findOrFail($flaggedAlert)->environment_type)->toBe(EnvironmentType::Production)
        ->and(Alert::query()->findOrFail($plainAlert)->environment_type)->toBe(EnvironmentType::Staging)
        ->and(Alert::query()->findOrFail($orphanAlert)->environment_type)->toBe(EnvironmentType::Staging);
});

test('rolling back restores the colour, the production flag and the application host', function () {
    $cases = [
        'production' => [Environment::factory()->production(), 'prod', EnvironmentType::Production],
        'preprod' => [Environment::factory()->preprod(), 'preprod', EnvironmentType::Preprod],
        'staging' => [Environment::factory()->staging(), 'staging', EnvironmentType::Staging],
        'develop' => [Environment::factory()->develop(), 'develop', EnvironmentType::Development],
        'demo' => [Environment::factory()->demo(), 'demo', EnvironmentType::Demo],
        'testing' => [Environment::factory()->testing(), 'testing', EnvironmentType::Testing],
    ];
    $environments = [];
    $alerts = [];

    foreach ($cases as $key => [$factory]) {
        $environments[$key] = $factory->for($this->application)->create(
            $key === 'production' ? ['horizon_url' => 'https://billing.example.com/horizon'] : [],
        );
        $alerts[$key] = Alert::factory()->for($environments[$key])->create();
    }

    $empty = Application::factory()->create(['name' => 'Empty']);

    ($this->rollBack)();

    $rows = DB::table('environments')->whereIn('id', array_map(fn (Environment $environment) => $environment->id, $environments))->get()->keyBy('id');

    foreach ($cases as $key => [, $color]) {
        $id = $environments[$key]->id;

        expect($rows[$id]->color)->toBe($color)
            ->and((bool) $rows[$id]->is_production)->toBe($key === 'production')
            ->and(DB::table('alerts')->where('id', $alerts[$key]->id)->value('environment_color'))->toBe($color);
    }

    expect(DB::table('applications')->where('id', $this->application->id)->value('host'))->toBe('billing.example.com')
        ->and(DB::table('applications')->where('id', $empty->id)->value('host'))->toBe('')
        ->and(Schema::hasColumn('environments', 'type'))->toBeFalse()
        ->and(Schema::hasColumn('alerts', 'environment_type'))->toBeFalse();

    ($this->migrate)();

    foreach ($cases as $key => [, , $type]) {
        expect($environments[$key]->fresh()->type)->toBe($type)
            ->and($alerts[$key]->fresh()->environment_type)->toBe($type);
    }
});
