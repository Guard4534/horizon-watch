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
            ->where('canManageApplications', true)
            ->where('visibilityRestricted', false)
            ->where('organizationHasEnvironments', false));
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
            ->where('canManageApplications', false)
            ->where('visibilityRestricted', true)
            ->where('organizationHasEnvironments', true));
})->with('monitoring pages');

test('a member who sees no environment gets empty counts, and the flags that say why', function () {
    [$member, $slug] = memberWhoSeesNothing();

    $this->actingAs($member);

    $this->get(route('wall', ['current_team' => $slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments', [])
            // The pair that makes the wall say "hidden from you" rather
            // than "nothing configured": the organization does hold
            // environments, this member just sees none of them.
            ->where('visibilityRestricted', true)
            ->where('organizationHasEnvironments', true)
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

/**
 * Role and visibility are independent: an admin may hold "manual" and a
 * viewer "all". The empty states pick their wording from both flags, so
 * neither may be inferred from the other.
 */
test('the two shared flags follow their own axis', function (string $role, MemberVisibility $visibility, bool $canManage, bool $restricted) {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, [
        'role' => $role,
        'visibility' => $visibility->value,
    ]);

    $user->switchTeam($team);

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageApplications', $canManage)
            ->where('visibilityRestricted', $restricted));
})->with([
    // The restricted admin: keeps the action, and is told about the
    // environments they cannot see whenever there are any.
    'admin · manual' => ['admin', MemberVisibility::Manual, true, true],
    'owner · non_production' => ['owner', MemberVisibility::NonProduction, true, true],
    // The unrestricted viewer: must never be offered a wider visibility,
    // because theirs is already as wide as it gets.
    'viewer · all' => ['viewer', MemberVisibility::All, false, false],
    'member · all' => ['member', MemberVisibility::All, false, false],
]);

test('a restricted admin of an organization that has environments is not told to add another application', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    $application = Application::factory()->for($team)->create();
    Environment::factory()->for($application)->production()->create();

    $team->members()->attach($admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $admin->switchTeam($team);

    $this->actingAs($admin)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Nothing visible, yet the organization is configured: only
            // visibilityRestricted can tell the wall which wording to use.
            ->where('page.environments', [])
            ->where('canManageApplications', true)
            ->where('visibilityRestricted', true)
            ->where('organizationHasEnvironments', true));
});

test('the wall tells an application with no environment apart from no application at all', function () {
    Application::factory()->for($this->admin->currentTeam)->create();

    $this->actingAs($this->admin)
        ->get(route('wall', ['current_team' => $this->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments', [])
            // An application kept alive by DeleteEnvironment: the wall must
            // point at adding an environment, not another application.
            ->where('page.applicationCount', 1)
            ->where('visibilityRestricted', false)
            ->where('canManageApplications', true)
            // The organization holds no environment, so nothing is hidden
            // from anyone: what is missing is an environment, not access.
            ->where('organizationHasEnvironments', false));
});

test('the shared flags describe the requested organization, not the stale current one', function () {
    $user = User::factory()->create();

    $member = Team::factory()->create();
    $member->members()->attach($user, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $administered = Team::factory()->create();
    $administered->members()->attach($user, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::All->value,
    ]);

    // Both flags would come out the other way round if they read the team
    // the user happens to be on instead of the one EnsureTeamMembership
    // switched them to.
    $user->switchTeam($member);

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $administered->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTeam.slug', $administered->slug)
            ->where('canManageApplications', true)
            ->where('visibilityRestricted', false));
});

test('the wall goes back to its tiles as soon as one environment is visible', function () {
    $application = Application::factory()->for($this->admin->currentTeam)->create();
    Environment::factory()->for($application)->production()->create();

    $this->actingAs($this->admin)
        ->get(route('wall', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 1)
            ->where('page.applicationCount', 1));
});

test('a restricted viewer of an empty organization is not told something is hidden', function () {
    $viewer = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($viewer, [
        'role' => TeamRole::Viewer->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $viewer->switchTeam($team);

    $this->actingAs($viewer)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Restricted, yet nothing at all is hidden: the organization is
            // empty. Only the second flag keeps the wall from claiming
            // there are environments this viewer cannot see.
            ->where('visibilityRestricted', true)
            ->where('organizationHasEnvironments', false)
            ->where('canManageApplications', false)
            ->where('page.environments', []));
});

test('a restricted admin whose only application has no environment hears about the environment', function () {
    $admin = User::factory()->create();
    $team = Team::factory()->create();

    Application::factory()->for($team)->create();

    $team->members()->attach($admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $admin->switchTeam($team);

    $this->actingAs($admin)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Restricted and empty-handed, but nothing is being kept from
            // them: the application they can see simply has no environment
            // yet, which is what the wall must say.
            ->where('visibilityRestricted', true)
            ->where('organizationHasEnvironments', false)
            ->where('canManageApplications', true)
            ->where('page.applicationCount', 1)
            ->where('page.environments', []));
});
