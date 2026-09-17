<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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

/**
 * The sidebar badge is the one monitoring number that is not a page prop:
 * it is shared, so it renders on every page and four pages re-poll it, and
 * it comes from a *second* repository instance resolved inside
 * HandleInertiaRequests. That instance reads the viewer from the guard on
 * its own, so nothing but this test says the badge is filtered like the
 * pages it sits next to. Until now it was only asserted to be an integer.
 *
 * "fatturaomatic-production" is one of GeneratedMetrics' fixed incidents,
 * so it always carries an open alert; "fatturaomatic-staging" is
 * deterministically healthy.
 */
test('the sidebar badge does not count an incident the viewer cannot see', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->create(['name' => 'Fatturaomatic']);
    Environment::factory()->for($application)->production()->create();
    Environment::factory()->for($application)->staging()->create();

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
