<?php

use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
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
            ->where('page.overrideCount', 3));
});

test('the alert log defaults to open alerts and can switch state', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'open')->where('page.counts.muted', 1));

    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug, 'state' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'resolved')->has('page.alerts', 2));
});

test('alert rules default to the organization scope', function () {
    $this->actingAs($this->user)
        ->get(route('alert-rules.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scope', 'organization')
            ->has('page.scopes', 5)
            ->has('page.rules', 8)
            ->where('page.notifications.repeatMinutes', 30));
});
