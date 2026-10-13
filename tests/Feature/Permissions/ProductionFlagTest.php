<?php

use App\Models\Environment;
use Illuminate\Support\Facades\DB;

test('an environment name looks like production', function (string $name, bool $production) {
    expect(Environment::looksLikeProduction($name))->toBe($production);
})->with([
    'production' => ['production', true],
    'prod' => ['prod', true],
    'prd' => ['prd', true],
    'live' => ['live', true],
    'production with a region' => ['production-eu', true],
    'prod with an underscore suffix' => ['prod_us', true],
    'upper case' => ['PROD', true],
    'staging' => ['staging', false],
    'preprod' => ['preprod', false],
    'production as a suffix' => ['pre-production', false],
    'a word starting with prod' => ['products', false],
    'a word starting with live' => ['livestream', false],
]);

test('a new environment is flagged from its name unless the flag is given', function () {
    expect(Environment::factory()->create(['name' => 'prod-eu'])->is_production)->toBeTrue()
        ->and(Environment::factory()->staging()->create()->is_production)->toBeFalse()
        ->and(Environment::factory()->create(['name' => 'blue', 'is_production' => true])->is_production)->toBeTrue()
        ->and(Environment::factory()->create(['name' => 'production', 'is_production' => false])->is_production)->toBeFalse();
});

test('the backfill flags the existing environments whose name looks like production', function () {
    $names = ['production', 'prod', 'prd', 'live', 'production-eu', 'prod_us', 'staging', 'preprod', 'pre-production', 'products'];
    $ids = [];

    foreach ($names as $name) {
        $ids[$name] = Environment::factory()->create(['name' => $name, 'is_production' => false])->id;
    }

    $kept = Environment::factory()->create(['name' => 'blue', 'is_production' => true]);

    $migration = require database_path('migrations/2026_10_13_040002_backfill_is_production_on_environments_table.php');
    $migration->up();

    $flags = DB::table('environments')->whereIn('id', $ids)->pluck('is_production', 'id')->map(fn ($flag) => (bool) $flag);

    expect(array_map(fn (int $id) => $flags[$id], $ids))->toBe([
        'production' => true,
        'prod' => true,
        'prd' => true,
        'live' => true,
        'production-eu' => true,
        'prod_us' => true,
        'staging' => false,
        'preprod' => false,
        'pre-production' => false,
        'products' => false,
    ])
        ->and($kept->fresh()->is_production)->toBeTrue();
});
