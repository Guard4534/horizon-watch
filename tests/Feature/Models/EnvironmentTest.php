<?php

use App\Enums\EnvironmentType;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('the slug is composed from the application and the environment name', function () {
    $application = Application::factory()->create(['name' => 'Invoice Desk']);

    $environment = Environment::factory()->production()->create([
        'application_id' => $application->id,
    ]);

    expect($environment->slug)->toBe('invoice-desk-production');
});

test('the slug is unique within the organization even across applications with same-named environments', function () {
    $team = Team::factory()->create();
    $firstApplication = Application::factory()->create(['team_id' => $team->id, 'name' => 'Invoice Desk']);
    $secondApplication = Application::factory()->create(['team_id' => $team->id, 'name' => 'Invoice Desk Two']);

    $first = Environment::factory()->production()->create(['application_id' => $firstApplication->id]);
    $second = Environment::factory()->production()->create(['application_id' => $secondApplication->id]);

    expect($first->slug)->toBe('invoice-desk-production')
        ->and($second->slug)->toBe('invoice-desk-two-production');
});

test('an application with the longest name still gets environment slugs that fit, keeping the environment name', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->create(['team_id' => $team->id, 'name' => str_repeat('a', 255)]);

    $production = Environment::factory()->production()->create(['application_id' => $application->id]);
    $staging = Environment::factory()->staging()->create(['application_id' => $application->id]);

    expect(strlen($application->slug))->toBe(240)
        ->and(strlen($production->slug))->toBeLessThanOrEqual(255)
        ->and($production->slug)->toEndWith('-production')
        ->and($staging->slug)->toEndWith('-staging');
});

test('the basic auth password is stored encrypted and hidden from arrays', function () {
    $environment = Environment::factory()->production()->create([
        'basic_auth_password' => 'super-secret',
    ]);

    $rawValue = DB::table('environments')->where('id', $environment->id)->value('basic_auth_password');

    expect($rawValue)->not->toBeNull()
        ->and($rawValue)->not->toContain('super-secret')
        ->and($environment->basic_auth_password)->toBe('super-secret')
        ->and($environment->toArray())->not->toHaveKey('basic_auth_password');
});

test('deleting an application cascades to its environments', function () {
    $application = Application::factory()->create();
    $environment = Environment::factory()->create(['application_id' => $application->id]);

    $application->delete();

    expect(Environment::find($environment->id))->toBeNull();
});

test('deleting an environment cascades to environment_user rows', function () {
    $environment = Environment::factory()->create();
    $user = User::factory()->create();

    DB::table('environment_user')->insert([
        'environment_id' => $environment->id,
        'user_id' => $user->id,
    ]);

    $environment->delete();

    expect(DB::table('environment_user')->where('environment_id', $environment->id)->exists())->toBeFalse();
});

test('the database refuses a duplicate slug inside one organization', function () {
    $team = Team::factory()->create();
    $first = Application::factory()->create(['team_id' => $team->id, 'name' => 'Acme']);
    $second = Application::factory()->create(['team_id' => $team->id, 'name' => 'Acme Shop']);

    $existing = Environment::factory()->production()->create(['application_id' => $first->id]);

    expect(fn () => DB::table('environments')->insert([
        'application_id' => $second->id,
        'team_id' => $team->id,
        'name' => 'other',
        'slug' => $existing->slug,
        'type' => 'production',
        'horizon_url' => 'https://other.example.com/horizon/api',
        'poll_interval_seconds' => 15,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('the same slug is still free in another organization', function () {
    $first = Application::factory()->create(['name' => 'Acme']);
    $second = Application::factory()->create(['name' => 'Acme']);

    $mine = Environment::factory()->production()->create(['application_id' => $first->id]);
    $theirs = Environment::factory()->production()->create(['application_id' => $second->id]);

    expect($mine->slug)->toBe('acme-production')
        ->and($theirs->slug)->toBe('acme-production')
        ->and($mine->team_id)->not->toBe($theirs->team_id);
});

test('the environment carries its application organization, which is what the constraint is scoped by', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->create(['team_id' => $team->id]);

    $environment = Environment::factory()->create(['application_id' => $application->id]);

    expect($environment->team_id)->toBe($team->id)
        ->and($environment->fresh()->team_id)->toBe($team->id);
});

test('a new environment takes the type its name suggests, unless one is given', function () {
    $application = Application::factory()->create();
    $make = fn (array $attributes): Environment => $application->environments()->create([
        'horizon_url' => 'https://shop.example.com/horizon',
        'poll_interval_seconds' => 15,
        ...$attributes,
    ]);

    expect($make(['name' => 'prod-eu'])->type)->toBe(EnvironmentType::Production)
        ->and($make(['name' => 'qa'])->type)->toBe(EnvironmentType::Testing)
        ->and($make(['name' => 'blue'])->type)->toBe(EnvironmentType::Staging)
        ->and($make(['name' => 'production-b', 'type' => EnvironmentType::Demo])->type)->toBe(EnvironmentType::Demo);
});
