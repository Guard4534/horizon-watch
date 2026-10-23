<?php

use App\Data\Applications\EnvironmentFormData;
use App\Enums\EnvironmentType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

function importRuleErrors(array $overrides = []): array
{
    $payload = [
        'name' => 'production',
        'type' => 'production',
        'horizonUrl' => 'https://shop.example.com/horizon',
        'basicAuthUser' => 'ops',
        'pollIntervalSeconds' => 60,
        'pollingEnabled' => true,
        ...$overrides,
    ];

    return Validator::make($payload, EnvironmentFormData::importRules())->errors()->all();
}

test('the import rules accept a basic-auth user without a password and an existing name', function () {
    $application = Application::factory()->create();
    Environment::factory()->for($application)->create(['name' => 'production']);

    expect(importRuleErrors())->toBe([]);
});

test('the import rules are the rules of the form', function (array $overrides, string $message) {
    expect(importRuleErrors($overrides))->toContain($message);
})->with([
    'reserved name' => [['name' => 'organization'], 'This name is reserved for the organization-wide alert rules.'],
    'unknown type' => [['type' => 'worker'], 'The selected type is invalid.'],
    'credentials in the url' => [['horizonUrl' => 'https://ops:secret@shop.example.com/horizon'], 'Put the credentials in the basic-auth fields, not in the Horizon URL.'],
    'a network the panel never calls' => [['horizonUrl' => 'http://169.254.169.254/horizon'], 'This address points to a network the panel does not call.'],
]);

test('the import rules refuse a name in capitals, a missing type and an interval out of bounds', function (array $overrides) {
    expect(importRuleErrors($overrides))->toHaveCount(1);
})->with([
    'capitals' => [['name' => 'Production']],
    'no type' => [['type' => null]],
    'off the tick' => [['pollIntervalSeconds' => 20]],
    'over the maximum' => [['pollIntervalSeconds' => 600]],
]);

test('an environment that waits for its password cannot turn collection on without it', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $environment = Environment::factory()->for(Application::factory()->for($team))->create([
        'name' => 'production',
        'type' => EnvironmentType::Production,
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => 'ops',
        'basic_auth_password' => null,
        'poll_interval_seconds' => 60,
        'polling_enabled' => false,
    ]);
    $update = route('environments.update', ['current_team' => $team->slug, 'environment' => $environment->slug]);
    $payload = [
        'name' => 'production',
        'type' => 'production',
        'horizonUrl' => 'https://shop.example.com/horizon',
        'basicAuthUser' => 'ops',
        'basicAuthPassword' => null,
        'pollIntervalSeconds' => 60,
        'pollingEnabled' => true,
    ];

    expect($environment->waitsForPassword())->toBeTrue();

    $this->actingAs($admin)->patch($update, $payload)
        ->assertSessionHasErrors(['basicAuthPassword' => 'Enter the basic-auth password before turning collection on.']);

    $this->actingAs($admin)->patch($update, [...$payload, 'pollingEnabled' => false, 'pollIntervalSeconds' => 120])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->patch($update, [...$payload, 'basicAuthPassword' => 'typed-at-last'])
        ->assertSessionHasNoErrors();

    $environment->refresh();

    expect($environment->basic_auth_password)->toBe('typed-at-last')
        ->and($environment->polling_enabled)->toBeTrue()
        ->and($environment->waitsForPassword())->toBeFalse();
});

test('an environment already collecting without a password can still be saved, but not given another user', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $environment = Environment::factory()->for(Application::factory()->for($team))->create([
        'name' => 'production',
        'type' => EnvironmentType::Production,
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => 'ops',
        'basic_auth_password' => null,
        'poll_interval_seconds' => 60,
        'polling_enabled' => true,
    ]);
    $update = route('environments.update', ['current_team' => $team->slug, 'environment' => $environment->slug]);
    $payload = [
        'name' => 'production',
        'type' => 'production',
        'horizonUrl' => 'https://shop.example.com/horizon',
        'basicAuthUser' => 'ops',
        'basicAuthPassword' => null,
        'pollIntervalSeconds' => 120,
        'pollingEnabled' => true,
    ];

    $this->actingAs($admin)->patch($update, $payload)->assertSessionHasNoErrors();

    expect($environment->fresh()->poll_interval_seconds)->toBe(120);

    $this->actingAs($admin)->patch($update, [...$payload, 'basicAuthUser' => 'someone-else'])
        ->assertSessionHasErrors(['basicAuthPassword' => 'Enter the basic-auth password before turning collection on.']);
});
