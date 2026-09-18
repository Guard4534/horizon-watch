<?php

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Arr;

beforeEach(function () {
    $this->team = Team::factory()->create();
    Environment::factory()->for(Application::factory()->for($this->team))->production()->create();

    $this->admin = User::factory()->create(['locale' => Locale::It]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $this->admin->switchTeam($this->team);
});

test('a threshold above its maximum is reported in italian', function () {
    $this->actingAs($this->admin)
        ->put(route('alert-rules.update', ['current_team' => $this->team->slug, 'scope' => 'organization']), [
            'rules' => [[
                'metric' => AlertRuleMetric::JobsFailedPerHour->value,
                'threshold' => 50,
                'severity' => AlertSeverity::Warning->value,
                'notifyByEmail' => false,
                'enabled' => true,
            ]],
        ])
        ->assertSessionHasErrors(['rules.0.threshold' => 'Il campo soglia non può essere maggiore di 49.']);
});

test('quiet hours without an end are reported in italian', function () {
    $this->actingAs($this->admin)
        ->put(route('alert-settings.update', ['current_team' => $this->team->slug]), [
            'recipients' => [],
            'webhookUrl' => null,
            'quietFrom' => '23:00',
            'quietTo' => null,
            'timezone' => 'Europe/Rome',
            'repeatMinutes' => null,
        ])
        ->assertSessionHasErrors([
            'quietTo' => 'Il campo fine della finestra di silenzio è obbligatorio quando è presente inizio della finestra di silenzio.',
        ]);
});

test('every english validation message has an italian line', function () {
    $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $italian = require lang_path('it/validation.php');

    $keys = fn (array $lines): array => array_keys(Arr::dot(Arr::except($lines, ['custom', 'attributes'])));

    expect(array_diff($keys($english), $keys($italian)))->toBe([])
        ->and(array_diff($keys($italian), $keys($english)))->toBe([]);

    foreach (Arr::dot(Arr::except($english, ['custom', 'attributes'])) as $key => $line) {
        preg_match_all('/:[a-z_]+/', $line, $expected);
        preg_match_all('/:[a-z_]+/', Arr::get($italian, $key), $actual);

        expect(array_unique($actual[0]))->toEqualCanonicalizing(array_unique($expected[0]), $key);
    }
});
