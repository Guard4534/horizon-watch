<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->slug = $this->admin->currentTeam->slug;
});

/**
 * An organization where nothing is visible yet: the environments exist but
 * this member's manual visibility covers none of them, which is the other
 * way to reach the same empty state.
 *
 * @return array{User, string}
 */
function memberWhoSeesNothing(): array
{
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $application = Application::factory()->for($team)->create();
    Environment::factory()->for($application)->production()->create();
    Environment::factory()->for($application)->staging()->create();

    $team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $member->switchTeam($team);

    return [$member, $team->slug];
}

dataset('monitoring pages', [
    'wall' => ['wall', 'monitoring/Wall'],
    'applications' => ['applications.index', 'monitoring/applications/Index'],
    'alerts' => ['alerts.index', 'monitoring/alerts/Index'],
    'alert rules' => ['alert-rules.index', 'monitoring/alert-rules/Index'],
]);

test('an organization with nothing configured still answers, and offers the way out', function (string $route, string $component) {
    $this->actingAs($this->admin)
        ->get(route($route, ['current_team' => $this->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('canManageApplications', true));
})->with('monitoring pages');

test('each page carries the count its empty state keys off', function () {
    $this->actingAs($this->admin);

    $this->get(route('wall', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments', [])
            ->where('page.applicationCount', 0)
            ->where('page.kpis.environmentsTotal', 0));

    $this->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups', [])
            ->where('page.environmentCount', 0));

    $this->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environmentCount', 0)
            ->where('page.alerts', [])
            ->where('page.preview', null));

    // The organization scope is the only one left, and it counts nothing:
    // that is what the alert settings page keys off.
    $this->get(route('alert-rules.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.scopes', 1)
            ->where('page.scopes.0.id', 'organization')
            ->where('page.scopes.0.environmentCount', 0));
});

test('a member who sees no environment gets the same pages without the action', function (string $route, string $component) {
    [$member, $slug] = memberWhoSeesNothing();

    $this->actingAs($member)
        ->get(route($route, ['current_team' => $slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('canManageApplications', false));
})->with('monitoring pages');

test('a member who sees no environment is told nothing is configured, even though something is', function () {
    [$member, $slug] = memberWhoSeesNothing();

    $this->actingAs($member);

    $this->get(route('wall', ['current_team' => $slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments', [])
            // Not even the application behind the hidden environments: a
            // member with no permission to manage them sees no orphan.
            ->where('page.applicationCount', 0));

    $this->get(route('applications.index', ['current_team' => $slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.groups', []));

    $this->get(route('alerts.index', ['current_team' => $slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.environmentCount', 0));

    $this->get(route('alert-rules.index', ['current_team' => $slug]))
        ->assertInertia(fn (Assert $page) => $page->has('page.scopes', 1));
});

test('the shared permission follows the role, not the empty organization', function (string $role, bool $expected) {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, [
        'role' => $role,
        'visibility' => MemberVisibility::All->value,
    ]);

    $user->switchTeam($team);

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('canManageApplications', $expected));
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', false],
    'viewer' => ['viewer', false],
]);

test('the wall goes back to its tiles as soon as one environment is visible', function () {
    $application = Application::factory()->for($this->admin->currentTeam)->create();
    Environment::factory()->for($application)->production()->create();

    $this->actingAs($this->admin)
        ->get(route('wall', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 1)
            ->where('page.applicationCount', 1));
});
