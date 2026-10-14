<?php

use App\Alerts\DeliveryPolicy;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\MaintenanceScope;
use App\Enums\MemberVisibility;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Jobs\SendAlertEmail;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AlertTeam;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00'));

    $this->team = Team::factory()->create();
    $this->billing = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $this->production = Environment::factory()->for($this->billing)->production()->create();
    $this->staging = Environment::factory()->for($this->billing)->staging()->create();

    $this->memberAs = function (TeamRole $role, MemberVisibility $visibility = MemberVisibility::All): User {
        $user = User::factory()->create(['name' => 'Ada '.$role->value]);
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->schedule = fn (array $input = []) => $this->from(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->post(route('maintenance-windows.store', ['current_team' => $this->team->slug]), [
            'scope' => 'environment',
            'target' => $this->production->slug,
            'startsAt' => '2026-09-18T10:00:00Z',
            'endsAt' => '2026-09-18T12:00:00Z',
            'reason' => 'Database upgrade',
            ...$input,
        ]);

    $this->silence = fn (Environment $environment, mixed $minutes = 30) => $this->from(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $environment->slug]))
        ->post(route('environments.silence', ['current_team' => $this->team->slug, 'environment' => $environment->slug]), ['minutes' => $minutes]);

    $this->end = fn (MaintenanceWindow $window, ?Team $team = null) => $this->from(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->delete(route('maintenance-windows.destroy', ['current_team' => ($team ?? $this->team)->slug, 'window' => $window->id]));

    $this->settingsPage = function (): array {
        $props = null;

        $this->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props']['page'];
            });

        return $props;
    };
});

test('a role that may mute schedules a window for an environment, an application or the whole organization', function (TeamRole $role, string $scope, ?string $target) {
    $user = ($this->memberAs)($role);

    $this->actingAs($user);
    ($this->schedule)([
        'scope' => $scope,
        'target' => match ($target) {
            'application' => $this->billing->slug,
            'environment' => $this->production->slug,
            default => null,
        },
        'startsAt' => '2026-09-18T14:00:00+02:00',
        'endsAt' => '2026-09-18T15:30:00+02:00',
        'reason' => '  Database upgrade  ',
    ])
        ->assertRedirect(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maintenance window scheduled.']);

    $window = MaintenanceWindow::query()->sole();

    expect($window->team_id)->toBe($this->team->id)
        ->and($window->scope)->toBe(MaintenanceScope::from($scope))
        ->and($window->application_id)->toBe($target === 'application' ? $this->billing->id : null)
        ->and($window->environment_id)->toBe($target === 'environment' ? $this->production->id : null)
        ->and($window->starts_at->utc()->toDateTimeString())->toBe('2026-09-18 12:00:00')
        ->and($window->ends_at->utc()->toDateTimeString())->toBe('2026-09-18 13:30:00')
        ->and($window->reason)->toBe('Database upgrade')
        ->and($window->created_by)->toBe($user->id);
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member])->with([
    'environment' => ['environment', 'environment'],
    'application' => ['application', 'application'],
    'organization' => ['organization', null],
]);

test('a viewer can neither schedule, silence nor end a window', function () {
    $window = MaintenanceWindow::factory()->for($this->team)->create();

    $this->actingAs(($this->memberAs)(TeamRole::Viewer));

    ($this->schedule)()->assertForbidden();
    ($this->silence)($this->production)->assertForbidden();
    ($this->end)($window)->assertForbidden();

    expect(MaintenanceWindow::query()->pluck('id')->all())->toBe([$window->id]);
});

test('a member who sees part of the organization silences only the environments they see', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction));

    ($this->schedule)(['target' => $this->staging->slug])->assertSessionHasNoErrors()->assertRedirect();
    ($this->schedule)(['target' => $this->production->slug])->assertSessionHasErrors('target');
    ($this->schedule)(['scope' => 'application', 'target' => $this->billing->slug])->assertSessionHasErrors('target');
    ($this->schedule)(['scope' => 'organization', 'target' => null])->assertForbidden();
    ($this->silence)($this->production)->assertNotFound();

    expect(MaintenanceWindow::query()->pluck('environment_id')->all())->toBe([$this->staging->id]);
});

test('an environment of another organization cannot be targeted', function () {
    $other = Environment::factory()->for(Application::factory())->create();

    $this->actingAs(($this->memberAs)(TeamRole::Admin));

    ($this->schedule)(['target' => $other->slug])->assertSessionHasErrors('target');
    ($this->schedule)(['scope' => 'application', 'target' => $other->application->slug])->assertSessionHasErrors('target');

    expect(MaintenanceWindow::query()->count())->toBe(0);
});

test('the window is checked before it is saved', function (array $input, string $field) {
    $this->actingAs(($this->memberAs)(TeamRole::Admin));

    ($this->schedule)($input)->assertSessionHasErrors($field);

    expect(MaintenanceWindow::query()->count())->toBe(0);
})->with([
    'unknown scope' => [['scope' => 'everything'], 'scope'],
    'environment without target' => [['target' => null], 'target'],
    'end before start' => [['startsAt' => '2026-09-18T12:00:00Z', 'endsAt' => '2026-09-18T11:00:00Z'], 'endsAt'],
    'end already past' => [['startsAt' => '2026-09-18T08:00:00Z', 'endsAt' => '2026-09-18T09:00:00Z'], 'endsAt'],
    'not a date' => [['startsAt' => 'tomorrow-ish'], 'startsAt'],
    'longer than allowed' => [['startsAt' => '2026-09-18T10:00:00Z', 'endsAt' => '2026-10-19T10:00:01Z'], 'endsAt'],
    'reason too long' => [['reason' => str_repeat('a', 256)], 'reason'],
]);

test('the longest window follows the configuration', function () {
    config(['horizon-watch.alerts.maintenance_max_days' => 1]);
    $this->actingAs(($this->memberAs)(TeamRole::Admin));

    ($this->schedule)(['endsAt' => '2026-09-19T10:00:00Z'])->assertSessionHasNoErrors();
    ($this->schedule)(['endsAt' => '2026-09-19T10:00:01Z'])->assertSessionHasErrors('endsAt');
});

test('a start in the past is moved to now', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Admin));

    ($this->schedule)(['startsAt' => '2026-09-18T09:00:00Z'])->assertSessionHasNoErrors();

    expect(MaintenanceWindow::query()->sole()->starts_at->utc()->toDateTimeString())->toBe('2026-09-18 10:00:00');
});

test('a window that starts now mutes the open alerts it covers at once, a planned one leaves them alone', function (string $startsAt, bool $muted) {
    $alert = Alert::factory()->for($this->production)->critical()->create(['metric' => AlertRuleMetric::EndpointUnreachable]);
    $outside = Alert::factory()->for($this->staging)->create();
    $resolved = Alert::factory()->for($this->production)->resolved()->create();

    $this->actingAs(($this->memberAs)(TeamRole::Member));
    ($this->schedule)(['startsAt' => $startsAt])->assertSessionHasNoErrors();

    $window = MaintenanceWindow::query()->sole();

    expect($alert->fresh()->state(CarbonImmutable::now()))->toBe($muted ? AlertState::Muted : AlertState::Open)
        ->and($alert->fresh()->muted_until?->toDateTimeString())->toBe($muted ? '2026-09-18 12:00:00' : null)
        ->and($alert->fresh()->maintenance_window_id)->toBe($muted ? $window->id : null)
        ->and($outside->fresh()->muted_until)->toBeNull()
        ->and($resolved->fresh()->muted_until)->toBeNull();
})->with([
    'now' => ['2026-09-18T10:00:00Z', true],
    'later' => ['2026-09-18T11:00:00Z', false],
]);

test('silencing an environment opens a window from now for the chosen minutes and mutes its alerts', function () {
    $alert = Alert::factory()->for($this->production)->critical()->create(['metric' => AlertRuleMetric::EndpointUnreachable]);
    $user = ($this->memberAs)(TeamRole::Member);

    $this->actingAs($user);
    ($this->silence)($this->production, 60)
        ->assertRedirect(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $this->production->slug]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alerts silenced.']);

    $window = MaintenanceWindow::query()->sole();

    expect($window->scope)->toBe(MaintenanceScope::Environment)
        ->and($window->environment_id)->toBe($this->production->id)
        ->and($window->starts_at->toDateTimeString())->toBe('2026-09-18 10:00:00')
        ->and($window->ends_at->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($window->reason)->toBeNull()
        ->and($window->created_by)->toBe($user->id)
        ->and($alert->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 11:00:00');
});

test('the silence lasts one of the configured durations', function (mixed $minutes, bool $accepted) {
    $this->actingAs(($this->memberAs)(TeamRole::Member));

    $response = ($this->silence)($this->production, $minutes);

    $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('minutes');
    expect(MaintenanceWindow::query()->count())->toBe($accepted ? 1 : 0);
})->with([
    'thirty minutes' => [30, true],
    'four hours' => [240, true],
    'not offered' => [45, false],
    'missing' => [null, false],
]);

test('ending a window that is under way cuts it short and unmutes the alerts it muted', function () {
    $window = MaintenanceWindow::factory()->forEnvironment($this->production)->between('2026-09-18 09:00:00', '2026-09-18 12:00:00')->create();
    $muted = Alert::factory()->for($this->production)->create(['muted_until' => $window->ends_at, 'maintenance_window_id' => $window->id]);
    $byHand = Alert::factory()->for($this->production)->create([
        'metric' => AlertRuleMetric::QueueMaxWait,
        'muted_until' => '2026-09-18 11:00:00',
        'muted_by' => ($this->memberAs)(TeamRole::Admin)->id,
        'maintenance_window_id' => $window->id,
    ]);
    $resolved = Alert::factory()->for($this->production)->resolved()->create(['muted_until' => $window->ends_at, 'maintenance_window_id' => $window->id]);

    $this->actingAs(($this->memberAs)(TeamRole::Member));
    ($this->end)($window)
        ->assertRedirect(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maintenance window ended.']);

    expect($window->fresh()->ends_at->toDateTimeString())->toBe('2026-09-18 10:00:00')
        ->and($muted->fresh()->state(CarbonImmutable::now()))->toBe(AlertState::Open)
        ->and($byHand->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($resolved->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 12:00:00');
});

test('a planned or finished window is deleted', function (string $startsAt, string $endsAt) {
    $window = MaintenanceWindow::factory()->for($this->team)->between($startsAt, $endsAt)->create();

    $this->actingAs(($this->memberAs)(TeamRole::Admin));
    ($this->end)($window)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maintenance window deleted.']);

    expect(MaintenanceWindow::query()->count())->toBe(0);
})->with([
    'planned' => ['2026-09-18 11:00:00', '2026-09-18 12:00:00'],
    'finished' => ['2026-09-18 08:00:00', '2026-09-18 09:00:00'],
]);

test('a window is ended only by someone who sees what it covers, and only in its own organization', function () {
    $organization = MaintenanceWindow::factory()->for($this->team)->create();
    $production = MaintenanceWindow::factory()->forEnvironment($this->production)->create();
    $staging = MaintenanceWindow::factory()->forEnvironment($this->staging)->create();
    $elsewhere = MaintenanceWindow::factory()->create();

    $restricted = ($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction);
    $this->actingAs($restricted);

    ($this->end)($organization)->assertForbidden();
    ($this->end)($production)->assertForbidden();
    ($this->end)($elsewhere)->assertNotFound();
    ($this->end)($elsewhere, $elsewhere->team)->assertForbidden();
    $this->delete(route('maintenance-windows.destroy', ['current_team' => $this->team->slug, 'window' => 'abc']))->assertNotFound();
    ($this->end)($staging)->assertRedirect();

    expect($staging->fresh()->ends_at->toDateTimeString())->toBe('2026-09-18 10:00:00')
        ->and($organization->fresh()->ends_at->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($production->fresh()->ends_at->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($elsewhere->fresh()->ends_at->toDateTimeString())->toBe('2026-09-18 11:00:00');
});

test('the alert settings list the windows under way, the planned ones and the latest finished, with who may end them', function () {
    config(['horizon-watch.pages.ended_maintenance_windows' => 1]);
    $admin = ($this->memberAs)(TeamRole::Admin);
    $active = MaintenanceWindow::factory()->forEnvironment($this->production)->create(['reason' => 'Deploy', 'created_by' => $admin->id]);
    $planned = MaintenanceWindow::factory()->forApplication($this->billing)->between('2026-09-19 08:00:00', '2026-09-19 09:00:00')->create();
    $finished = MaintenanceWindow::factory()->for($this->team)->between('2026-09-18 07:00:00', '2026-09-18 09:00:00')->create();
    MaintenanceWindow::factory()->for($this->team)->between('2026-09-18 05:00:00', '2026-09-18 06:00:00')->create();
    MaintenanceWindow::factory()->create();

    $this->actingAs($admin);
    $page = ($this->settingsPage)();

    expect(collect($page['maintenanceWindows'])->pluck('id')->all())->toBe([$active->id, $planned->id, $finished->id])
        ->and($page['maintenanceWindows'][0])->toMatchArray([
            'scope' => 'environment',
            'applicationName' => 'Billing',
            'environmentName' => 'production',
            'color' => 'prod',
            'startsAt' => '2026-09-18T09:55:00+00:00',
            'endsAt' => '2026-09-18T11:00:00+00:00',
            'active' => true,
            'ended' => false,
            'reason' => 'Deploy',
            'createdBy' => ['name' => $admin->name],
            'canDelete' => true,
        ])
        ->and($page['maintenanceWindows'][1])->toMatchArray(['scope' => 'application', 'applicationName' => 'Billing', 'environmentName' => null, 'active' => false, 'ended' => false])
        ->and($page['maintenanceWindows'][2])->toMatchArray(['scope' => 'organization', 'applicationName' => null, 'active' => false, 'ended' => true])
        ->and($page['maintenanceMaxDays'])->toBe(31)
        ->and(collect($page['maintenanceTargets'])->map(fn (array $target) => $target['scope'].':'.$target['id'])->all())
        ->toBe(['organization:', 'application:'.$this->billing->slug, 'environment:'.$this->production->slug, 'environment:'.$this->staging->slug]);
});

test('a restricted member sees the windows that reach their environments and may end only those of the environments they see', function () {
    $organization = MaintenanceWindow::factory()->for($this->team)->create();
    $application = MaintenanceWindow::factory()->forApplication($this->billing)->create();
    MaintenanceWindow::factory()->forEnvironment($this->production)->create();
    $staging = MaintenanceWindow::factory()->forEnvironment($this->staging)->create();
    MaintenanceWindow::factory()->forApplication(Application::factory()->for($this->team)->create())->create();

    $this->actingAs(($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction));
    $page = ($this->settingsPage)();

    expect(collect($page['maintenanceWindows'])->pluck('canDelete', 'id')->all())->toBe([
        $organization->id => false,
        $application->id => false,
        $staging->id => true,
    ])
        ->and(collect($page['maintenanceTargets'])->map(fn (array $target) => $target['scope'].':'.$target['id'])->all())
        ->toBe(['environment:'.$this->staging->slug]);
});

test('a viewer sees the windows but may schedule nothing', function () {
    $window = MaintenanceWindow::factory()->for($this->team)->create();

    $this->actingAs(($this->memberAs)(TeamRole::Viewer));
    $page = ($this->settingsPage)();

    expect(collect($page['maintenanceWindows'])->pluck('canDelete', 'id')->all())->toBe([$window->id => false])
        ->and($page['maintenanceTargets'])->toBe([]);
});

test('the environment page and the wall mark an environment in maintenance', function () {
    MaintenanceWindow::factory()->forApplication($this->billing)->create(['reason' => 'Upgrade']);
    MaintenanceWindow::factory()->forEnvironment($this->production)->between('2026-09-18 09:00:00', '2026-09-18 10:30:00')->create();
    MaintenanceWindow::factory()->forEnvironment($this->staging)->between('2026-09-18 11:00:00', '2026-09-18 12:00:00')->create();

    $this->actingAs(($this->memberAs)(TeamRole::Member));

    $this->get(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $this->production->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.maintenanceUntil', '2026-09-18T11:00:00+00:00')
            ->where('page.environment.maintenanceReason', 'Upgrade')
            ->where('page.canSilence', true)
            ->where('page.silenceChoices', [30, 60, 120, 240]));

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where(
            'page.environments',
            fn ($environments) => collect($environments)->pluck('maintenanceUntil', 'id')->all() === [
                $this->production->slug => '2026-09-18T11:00:00+00:00',
                $this->staging->slug => '2026-09-18T11:00:00+00:00',
            ],
        ));
});

test('an environment outside any window carries no marker, and a viewer cannot silence it', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Viewer));

    $this->get(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $this->production->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.maintenanceUntil', null)
            ->where('page.environment.maintenanceReason', null)
            ->where('page.canSilence', false));
});

test('an alert muted by a window says so, while a mute by hand names its author', function () {
    $window = MaintenanceWindow::factory()->forEnvironment($this->production)->create();
    $admin = ($this->memberAs)(TeamRole::Admin);
    Alert::factory()->for($this->production)->create(['muted_until' => $window->ends_at, 'maintenance_window_id' => $window->id]);
    Alert::factory()->for($this->staging)->create(['muted_until' => '2026-09-18 11:00:00', 'muted_by' => $admin->id, 'maintenance_window_id' => $window->id]);

    $this->actingAs($admin)
        ->get(route('alerts.index', ['current_team' => $this->team->slug, 'state' => 'muted']))
        ->assertInertia(fn (Assert $page) => $page->where(
            'page.alerts',
            fn ($alerts) => collect($alerts)->sortBy('environmentName')->map(fn ($alert) => [$alert['environmentName'], $alert['mutedForMaintenance'], $alert['mutedBy']['name'] ?? null])->values()->all() === [
                ['production', true, null],
                ['staging', false, $admin->name],
            ],
        ));
});

test('the prune deletes the windows that ended before the alert retention', function () {
    config(['horizon-watch.alert_retention_days' => 90]);
    $kept = MaintenanceWindow::factory()->for($this->team)->between('2026-06-19 08:00:00', '2026-06-20 10:00:01')->create();
    MaintenanceWindow::factory()->for($this->team)->between('2026-06-19 08:00:00', '2026-06-20 09:59:59')->create();

    $this->artisan('monitoring:prune')
        ->expectsOutput('Deleted 1 maintenance window.')
        ->assertSuccessful();

    expect(MaintenanceWindow::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('deleting an environment or an application takes its windows along and keeps its alerts', function () {
    $window = MaintenanceWindow::factory()->forEnvironment($this->staging)->create();
    $alert = Alert::factory()->for($this->staging)->resolved()->create(['muted_until' => $window->ends_at, 'maintenance_window_id' => $window->id]);
    MaintenanceWindow::factory()->forApplication($this->billing)->create();
    $organization = MaintenanceWindow::factory()->for($this->team)->create();

    $this->billing->delete();

    expect(MaintenanceWindow::query()->pluck('id')->all())->toBe([$organization->id])
        ->and($alert->fresh()->maintenance_window_id)->toBeNull();
});

test('ending a window early hands its alerts to another window that still covers them', function () {
    Queue::fake();
    AlertTeam::member($this->team, 'admin@example.com', TeamRole::Admin);
    NotificationSetting::factory()->for($this->team)->withWebhook()->create(['recipients' => ['ops@example.com']]);
    $application = MaintenanceWindow::factory()->forApplication($this->billing)->between('2026-09-18 09:00:00', '2026-09-18 14:00:00')->create();
    $environment = MaintenanceWindow::factory()->forEnvironment($this->production)->between('2026-09-18 09:30:00', '2026-09-18 15:00:00')->create();
    $alert = Alert::factory()->for($this->production)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'muted_until' => $environment->ends_at,
        'maintenance_window_id' => $environment->id,
    ]);

    $this->actingAs(($this->memberAs)(TeamRole::Member));
    ($this->end)($environment)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maintenance window ended.']);

    expect($alert->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 14:00:00')
        ->and($alert->fresh()->maintenance_window_id)->toBe($application->id);

    app()->forgetScopedInstances();
    app(DeliveryPolicy::class)->repeatDue(CarbonImmutable::now());

    Queue::assertNothingPushed();

    $this->travelTo(CarbonImmutable::parse('2026-09-18 14:00:00'));
    app()->forgetScopedInstances();
    app(DeliveryPolicy::class)->repeatDue(CarbonImmutable::now());

    Queue::assertPushed(SendAlertEmail::class, fn (SendAlertEmail $job) => $job->kind === SentNotificationKind::CriticalAlert);
});

test('a mute by hand still running when a window starts outlives the window ended early', function () {
    $user = ($this->memberAs)(TeamRole::Admin);
    $alert = Alert::factory()->for($this->production)->create(['muted_until' => '2026-09-18 11:00:00', 'muted_by' => $user->id]);

    $this->actingAs($user);
    ($this->schedule)()->assertSessionHasNoErrors();

    expect($alert->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($alert->fresh()->maintenance_window_id)->toBeNull();

    $this->travel(10)->minutes();
    ($this->end)(MaintenanceWindow::query()->sole())->assertSessionHasNoErrors();

    expect($alert->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 11:00:00')
        ->and($alert->fresh()->muted_by)->toBe($user->id);
});

test('a window ended within the second it started is deleted', function () {
    $alert = Alert::factory()->for($this->production)->create();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00.400'));
    $this->actingAs(($this->memberAs)(TeamRole::Member));
    ($this->silence)($this->production)->assertSessionHasNoErrors();

    expect($alert->fresh()->state(CarbonImmutable::now()))->toBe(AlertState::Muted);

    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00.800'));
    ($this->end)(MaintenanceWindow::query()->sole())
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Maintenance window deleted.']);

    expect(MaintenanceWindow::query()->count())->toBe(0)
        ->and($alert->fresh()->state(CarbonImmutable::now()))->toBe(AlertState::Open);
});
