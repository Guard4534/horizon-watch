<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
    (new DatabaseSeeder)->seedMockupOrganization($this->user->currentTeam);
});

dataset('pages', [
    'wall' => ['wall', [], 'monitoring/Wall'],
    'applications' => ['applications.index', [], 'monitoring/applications/Index'],
    'application' => ['applications.show', ['application' => 'fatturaomatic'], 'monitoring/applications/Show'],
    'environment' => ['environments.show', ['environment' => 'fatturaomatic-production'], 'monitoring/environments/Show'],
    'alerts' => ['alerts.index', [], 'monitoring/alerts/Index'],
    'alert rules' => ['alert-rules.index', [], 'monitoring/alert-rules/Index'],
]);

test('members see every monitoring page', function (string $route, array $parameters, string $component) {
    $this->actingAs($this->user)
        ->get(route($route, ['current_team' => $this->slug, ...$parameters]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component)->has('page'));
})->with('pages');

test('guests are sent to the login page', function (string $route, array $parameters) {
    $this->get(route($route, ['current_team' => $this->slug, ...$parameters]))
        ->assertRedirect(route('login'));
})->with('pages');

test('nobody sees another organization', function (string $route, array $parameters) {
    $other = Team::factory()->create();

    $this->actingAs($this->user)
        ->get(route($route, ['current_team' => $other->slug, ...$parameters]))
        ->assertForbidden();
})->with('pages');

test('unknown applications, environments and scopes are not found', function (string $route, array $parameters) {
    $this->actingAs($this->user)
        ->get(route($route, ['current_team' => $this->slug, ...$parameters]))
        ->assertNotFound();
})->with([
    ['applications.show', ['application' => 'nope']],
    ['environments.show', ['environment' => 'nope']],
    ['alert-rules.index', ['scope' => 'nope']],
]);

test('the wall carries every environment and the key numbers', function () {
    $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 29)
            ->where('page.kpis.environmentsTotal', 29)
            ->where('page.environments.0.status', fn (string $status) => in_array($status, ['inactive', 'unreachable'], true))
            ->has('page.anomalies', 5)
            ->has('page.throughput', 48)
            ->has('page.notifications', 4)
            ->where('page.applicationCount', 9)
            ->where('openAlertCount', fn (int $count) => $count >= 6));
});

test('the environment page follows the requested range', function () {
    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'acme-shop-production', 'range' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.range', '7d')
            ->where('page.environment.id', 'acme-shop-production')
            ->has('page.nodes', 3)
            ->has('page.queues', 5)
            ->has('page.rules', 8)
            ->where('page.overrideCount', 3)
            ->where('page.scope', 'production'));
});

test('the alert log defaults to open alerts and can switch state', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'open')->where('page.counts.muted', 1));

    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug, 'state' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'resolved')->has('page.alerts', 2));
});

test('the application page reports its worst environment status', function () {
    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'fatturaomatic']))
        ->assertInertia(fn (Assert $page) => $page->where('page.worstStatus', 'inactive'));
});

test('alert rules default to the organization scope', function () {
    $this->actingAs($this->user)
        ->get(route('alert-rules.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scope', 'organization')
            // organization + every distinct environment name in the mockup
            // organization (production, preprod, staging, develop, demo,
            // worker-batch, testing): unlike phase 1's fixed list, this one
            // is computed from what's actually visible.
            ->has('page.scopes', 8)
            ->has('page.rules', 8)
            ->where('page.notifications.repeatMinutes', 30));
});

/**
 * "Un ambiente non visibile … la sua pagina risponde 404" (spec,
 * "Visibilità degli ambienti"), over HTTP: the repository returning null
 * and EnvironmentDetailQuery turning null into abort(404) were each tested
 * on their own, never joined, and the 404 tests above only cover slugs that
 * do not exist or belong to another organization. 404 and not 403 on
 * purpose: the page never tells a member that a slug they cannot see exists.
 */
test('an environment hidden from a member answers 404 on its own page', function (MemberVisibility $visibility, bool $grantsStaging) {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->create(['name' => 'Fatturaomatic']);
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();

    $member = User::factory()->create();
    $team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => $visibility->value,
    ]);

    if ($grantsStaging) {
        $member->teamMemberships()->where('team_id', $team->id)->first()
            ->visibleEnvironments()->attach([$staging->id]);
    }

    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('environments.show', ['current_team' => $team->slug, 'environment' => $production->slug]))
        ->assertNotFound();

    // And the fixture is sound: the environment this member does see opens.
    $this->actingAs($member)
        ->get(route('environments.show', ['current_team' => $team->slug, 'environment' => $staging->slug]))
        ->assertOk();
})->with([
    'non_production on a production slug' => [MemberVisibility::NonProduction, false],
    'manual on an ungranted slug' => [MemberVisibility::Manual, true],
]);

/**
 * The split the spec documents as rare: "un admin con manual vede solo i
 * suoi ambienti, ma li configura tutti dalla vista Applicativi (dove serve
 * il permesso, non la visibilità)". Both halves are asserted here, because
 * either one drifting alone is a bug: a narrow wall with a complete
 * Applications view. The write pages of the same admin are asserted in
 * EnvironmentCrudTest.
 */
test('a restricted admin watches their own environments and configures every one of them', function () {
    $team = Team::factory()->create();

    $mine = Application::factory()->for($team)->create(['name' => 'Alpha']);
    $theirs = Application::factory()->for($team)->create(['name' => 'Bravo']);
    $watched = Environment::factory()->for($mine)->staging()->create();
    $hidden = Environment::factory()->for($theirs)->production()->create();

    $admin = User::factory()->create();
    $team->members()->attach($admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);
    $admin->teamMemberships()->where('team_id', $team->id)->first()
        ->visibleEnvironments()->attach([$watched->id]);

    $admin->switchTeam($team);
    $this->actingAs($admin);

    // The watched view: one environment, one application, one scope.
    $this->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 1)
            ->where('page.environments.0.id', $watched->slug)
            ->where('page.applicationCount', 1)
            ->where('page.kpis.environmentsTotal', 1));

    // The configuration view: the whole organization, with a row (and
    // therefore an edit link) for the environment the wall never shows.
    $this->get(route('applications.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.groups', 2)
            ->where('page.environmentCount', 2)
            ->where('page.groups.1.application.id', $theirs->slug)
            ->where('page.groups.1.environments.0.id', $hidden->slug));

    $this->get(route('applications.show', ['current_team' => $team->slug, 'application' => $theirs->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.cards.0.environment.id', $hidden->slug));

    // The environment's own page stays the operational view, so it keeps
    // answering 404 even to this admin: they configure it from the list,
    // they do not watch it.
    $this->get(route('environments.show', ['current_team' => $team->slug, 'environment' => $hidden->slug]))
        ->assertNotFound();
});
