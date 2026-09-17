<?php

use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_020));
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::All->value,
    ]);
    $this->admin->switchTeam($this->team);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $this->production = Environment::factory()->for($this->application)->production()->create();
    $this->staging = Environment::factory()->for($this->application)->staging()->create();
});

/**
 * @return array<int, array<string, mixed>>
 */
function alertsOn(string $slug, AlertState $state = AlertState::Open): array
{
    $alerts = null;

    test()->get(route('alerts.index', ['current_team' => $slug, 'state' => $state->value]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$alerts) {
            $alerts = $page->toArray()['props']['page']['alerts'];
        });

    return $alerts;
}

test('open anomalies come from the stored readings, each with the start of its run', function () {
    // Healthy 45 minutes ago, unreachable from 30 minutes ago onwards.
    EnvironmentSnapshot::factory()->for($this->production)->create(['captured_at' => now()->subMinutes(45)]);
    EnvironmentSnapshot::factory()->for($this->production)->failed()->create(['captured_at' => now()->subMinutes(30)]);
    EnvironmentSnapshot::factory()->for($this->production)->failed()->create(['captured_at' => now()->subMinutes(15)]);
    Readings::record($this->production, EnvironmentStatus::Unreachable);

    EnvironmentSnapshot::factory()->for($this->staging)->create(['captured_at' => now()->subMinutes(20)]);
    EnvironmentSnapshot::factory()->for($this->staging)->degraded()->create(['captured_at' => now()->subMinutes(7)]);
    Readings::record($this->staging, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: ['pending' => 4_200]);

    $this->actingAs($this->admin);
    $alerts = alertsOn($this->team->slug);

    expect(array_map(fn (array $alert) => [
        $alert['environmentId'],
        $alert['metric'],
        $alert['severity'],
        $alert['environmentStatus'],
        $alert['minutesAgo'],
        $alert['sinceTruncated'],
    ], $alerts))->toBe([
        [$this->production->slug, 'endpoint.unreachable', 'critical', 'unreachable', 30, false],
        [$this->staging->slug, 'queue.pending', 'warning', 'degraded', 7, false],
    ])
        ->and($alerts[1]['pending'])->toBe(4_200)
        ->and($alerts[1]['applicationName'])->toBe('Billing');
});

test('an anomaly older than the look-back reaches the page as "more than 24 h"', function () {
    foreach ([30, 20, 10] as $hoursAgo) {
        EnvironmentSnapshot::factory()->for($this->production)->failed()->create(['captured_at' => now()->subHours($hoursAgo)]);
    }
    Readings::record($this->production, EnvironmentStatus::Unreachable);

    $this->actingAs($this->admin);
    $alerts = alertsOn($this->team->slug);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['sinceTruncated'])->toBeTrue()
        ->and($alerts[0]['minutesAgo'])->toBe(1440);
});

test('a paused horizon is an open warning on the page', function () {
    Readings::record($this->production, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused]);

    $this->actingAs($this->admin);
    $alerts = alertsOn($this->team->slug);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['metric'])->toBe('horizon.paused')
        ->and($alerts[0]['severity'])->toBe('warning')
        ->and($alerts[0]['environmentStatus'])->toBe('paused');
});

test('muted and resolved stay empty while anomalies are open', function (AlertState $state) {
    Readings::record($this->production, EnvironmentStatus::Unreachable);
    Readings::record($this->staging, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);

    $this->actingAs($this->admin)
        ->get(route('alerts.index', ['current_team' => $this->team->slug, 'state' => $state->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', $state->value)
            ->where('page.alerts', [])
            ->where('page.counts.open', 2)
            ->where('page.counts.muted', 0)
            ->where('page.counts.resolved', 0));
})->with([AlertState::Muted, AlertState::Resolved]);

test('the delivery-test box gets the notification targets, and the email preview is gone', function () {
    Readings::record($this->production, EnvironmentStatus::Unreachable);

    $this->actingAs($this->admin)
        ->get(route('alerts.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.notifications.recipients', ['ops@example.com', 'oncall@example.com'])
            ->where('page.notifications.webhookUrl', 'https://hooks.example.com/horizon')
            ->missing('page.preview'));
});

test('a member limited to non-production gets no anomaly of a production environment', function () {
    Readings::record($this->production, EnvironmentStatus::Unreachable);
    Readings::record($this->staging, EnvironmentStatus::Degraded, [AlertRuleMetric::QueueMaxWait]);

    $member = User::factory()->create();
    $this->team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);
    $member->switchTeam($this->team);

    $this->actingAs($member);
    $alerts = alertsOn($this->team->slug);

    expect(array_column($alerts, 'environmentId'))->toBe([$this->staging->slug])
        ->and(array_column($alerts, 'metric'))->toBe(['queue.max_wait']);
});

test('every scope lists the eight measurable rules, horizon.paused included and no redis.memory', function () {
    $this->actingAs($this->admin);

    foreach (['organization', 'production', 'staging'] as $scope) {
        $this->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => $scope]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('page.scope', $scope)
                ->where('page.rules', fn ($rules) => collect($rules)->pluck('metric')->all() === array_map(
                    fn (AlertRuleMetric $metric) => $metric->value,
                    AlertRuleMetric::cases(),
                ))
                ->has('page.rules', 8));
    }

    expect(AlertRuleMetric::tryFrom('redis.memory'))->toBeNull()
        ->and(array_column(AlertRuleMetric::cases(), 'value'))->toContain('horizon.paused');
});

test('the paused rule is a warning that is not emailed by default', function () {
    // Third in the enum, so third on the page.
    $this->actingAs($this->admin)
        ->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.rules.2.metric', 'horizon.paused')
            ->where('page.rules.2.threshold', fn ($threshold) => (float) $threshold === 15.0)
            ->where('page.rules.2.unit', 'min')
            ->where('page.rules.2.severity', 'warning')
            ->where('page.rules.2.notifyByEmail', false)
            ->where('page.rules.2.origin', 'organization'));
});
