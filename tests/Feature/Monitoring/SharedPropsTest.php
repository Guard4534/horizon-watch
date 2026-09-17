<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

test('pages share what the sidebar needs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('currentTeam.slug')
            ->has('teams')
            ->has('locale')
            ->where('openAlertCount', fn ($count) => is_int($count))
            ->has('auth.user.name'));
});

test('settings pages still render inside the new shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/profile')->assertOk();
});

test('the sidebar badge does not count an incident the viewer cannot see', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->create(['name' => 'Fatturaomatic']);
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    Readings::record($production, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record($staging);

    $watcher = User::factory()->create();
    $team->members()->attach($watcher, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::All->value,
    ]);
    $watcher->switchTeam($team);

    $restricted = User::factory()->create();
    $team->members()->attach($restricted, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);
    $restricted->switchTeam($team);

    $this->actingAs($watcher)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('openAlertCount', 1));

    $this->actingAs($restricted)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('openAlertCount', 0));
});
