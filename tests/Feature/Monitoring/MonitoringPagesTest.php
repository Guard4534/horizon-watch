<?php

use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Models\AlertNotification;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
    (new DatabaseSeeder)->seedMockupOrganization($this->user->currentTeam);
    Readings::mockup($this->user->currentTeam);
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
    AlertNotification::factory()->create([
        'team_id' => $this->user->currentTeam->id,
        'alert_id' => null,
        'kind' => SentNotificationKind::WarningDigest,
        'environment_count' => 2,
        'sent_at' => now()->subMinutes(3),
    ]);

    $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 29)
            ->where('page.kpis.environmentsTotal', 29)
            ->where('page.environments.0.status', fn (string $status) => in_array($status, ['inactive', 'unreachable'], true))
            ->where('page.kpis.openAnomalies', 6)
            ->where('page.kpis.environmentsUp', 26)
            ->has('page.anomalies', 5)
            ->has('page.throughput', 48)
            ->has('page.notifications', 1)
            ->where('page.notifications.0.kind', 'warning_digest')
            ->where('page.notifications.0.subject', '2')
            ->where('page.notifications.0.minutesAgo', 3)
            ->where('page.applicationCount', 9)
            ->where('openAlertCount', 6));
});

test('the environment page follows the requested range', function () {
    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'acme-shop-production', 'range' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.range', '7d')
            ->where('page.environment.id', 'acme-shop-production')
            ->has('page.nodes', 1)
            ->has('page.queues', 3)
            ->has('page.maxWait', 48)
            ->has('page.rules', 8));
});

test('the alert log defaults to open alerts and can switch state', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'open')
            ->where('page.counts.open', 6)
            ->has('page.alerts', 6)
            ->where('page.counts.muted', 0)
            ->where('page.counts.resolved', 0));

    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug, 'state' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'resolved')->where('page.alerts', []));
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
            ->has('page.scopes', 8)
            ->has('page.rules', 8)
            ->where('page.notifications.repeatMinutes', 30));
});

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

    $this->actingAs($member)
        ->get(route('environments.show', ['current_team' => $team->slug, 'environment' => $staging->slug]))
        ->assertOk();
})->with([
    'non_production on a production slug' => [MemberVisibility::NonProduction, false],
    'manual on an ungranted slug' => [MemberVisibility::Manual, true],
]);

test('a restricted admin watches their own environments and configures every one of them', function () {
    $team = Team::factory()->create();

    $mine = Application::factory()->for($team)->create(['name' => 'Alpha']);
    $theirs = Application::factory()->for($team)->create(['name' => 'Bravo']);
    $watched = Environment::factory()->for($mine)->staging()->create();
    $hidden = Environment::factory()->for($theirs)->production()->create();
    Readings::record($watched);
    Readings::record($hidden, EnvironmentStatus::Unreachable);

    $admin = User::factory()->create();
    $team->members()->attach($admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);
    $admin->teamMemberships()->where('team_id', $team->id)->first()
        ->visibleEnvironments()->attach([$watched->id]);

    $admin->switchTeam($team);
    $this->actingAs($admin);

    $this->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.environments', 1)
            ->where('page.environments.0.id', $watched->slug)
            ->where('page.environments.0.watched', true)
            ->where('page.applicationCount', 1)
            ->where('page.kpis.environmentsTotal', 1));

    $this->get(route('applications.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.groups', 2)
            ->where('page.environmentCount', 2)
            ->where('page.groups.0.application.id', $mine->slug)
            ->where('page.groups.0.environments.0.id', $watched->slug)
            ->where('page.groups.0.environments.0.watched', true)
            ->where('page.groups.1.application.id', $theirs->slug)
            ->where('page.groups.1.environments.0.id', $hidden->slug)
            ->where('page.groups.1.environments.0.watched', false)
            ->where('page.groups.1.environments.0.status', null)
            ->where('page.groups.1.environments.0.readingError', null)
            ->where('page.groups.1.triageCount', 0));

    $this->get(route('applications.show', ['current_team' => $team->slug, 'application' => $theirs->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.id', $hidden->slug)
            ->where('page.environments.0.watched', false)
            ->where('page.environments.0.trend', [])
            ->where('page.worstStatus', null)
            ->where('page.recentAlerts', []));

    $this->get(route('applications.show', ['current_team' => $team->slug, 'application' => $mine->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.watched', true)
            ->has('page.environments.0.trend', 12));

    $this->get(route('environments.show', ['current_team' => $team->slug, 'environment' => $hidden->slug]))
        ->assertNotFound();
});

test('the Applications pages only link watched environments to their detail page', function (string $template) {
    $source = file_get_contents(resource_path("js/components/monitoring/applications/{$template}"));

    preg_match_all('/<[A-Za-z][\w.-]*(?:\s+[^\s=>"\/]+(?:="[^"]*")?)*\s*\/?>/s', $source, $tags);

    $linking = array_values(array_filter(
        $tags[0],
        fn (string $tag) => str_contains($tag, 'showEnvironment(')
            || str_contains($tag, 'environmentHref(')
            || str_contains($tag, 'openRow('),
    ));

    expect($linking)->not->toBeEmpty();

    foreach ($linking as $tag) {
        expect($tag)->toContain('watched');
    }

    if (str_contains($source, 'openRow(')) {
        expect($source)->toContain('if (!environment.watched)');
    }
})->with([
    'the list rows' => 'ApplicationSection.vue',
    'the application cards' => 'EnvironmentCard.vue',
]);
