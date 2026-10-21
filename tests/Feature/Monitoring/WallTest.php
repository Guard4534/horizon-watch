<?php

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
use App\Queries\WallQuery;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 2, 30));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->admin->switchTeam($this->team);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Invoicer']);
    $this->production = Environment::factory()->for($this->application)->production()->create();
    $this->staging = Environment::factory()->for($this->application)->staging()->create();
});

function wallPage(User $user, Team $team): TestResponse
{
    return test()->actingAs($user)->get(route('wall', ['current_team' => $team->slug]));
}

/**
 * @param  list<int>  $pending
 */
function pendingHistory(Environment $environment, array $pending): void
{
    $last = count($pending) - 1;

    foreach ($pending as $index => $value) {
        $at = now()->subMinutes(5 * ($last - $index));

        if ($index === $last) {
            Readings::record($environment, snapshot: ['pending' => $value, 'captured_at' => $at]);

            continue;
        }

        EnvironmentSnapshot::factory()->for($environment)->create(['pending' => $value, 'captured_at' => $at]);
    }
}

test('each watched environment carries what its group and tile draw', function () {
    pendingHistory($this->staging, [100, 100, 100, 200, 200, 200]);
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending]);

    wallPage($this->admin, $this->team)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Wall')
            ->has('page.types', 6)->where('page.types.0.value', 'production')
            ->has('page.environments', 2)
            ->where('page.environments.0.id', $this->production->slug)
            ->where('page.environments.0.status', 'degraded')
            ->where('page.environments.1.id', $this->staging->slug)
            ->where('page.environments.1.applicationId', $this->application->slug)
            ->where('page.environments.1.applicationName', 'Invoicer')
            ->where('page.environments.1.status', 'active')
            ->where('page.environments.1.type', 'staging')
            ->where('page.environments.1.pending', 200)
            ->where('page.environments.1.trend', [0, 0, 0, 0, 0, 0, 100, 100, 100, 200, 200, 200])
            ->where('page.environments.1.trendPercent', 100)
            ->where('page.environments.1.thresholds', fn ($thresholds) => (float) $thresholds['jobs.failed_per_hour']['value'] === AlertRuleMetric::JobsFailedPerHour->defaultThreshold()));
});

test('an environment hidden from the viewer is not on the wall at all', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);
    $member->switchTeam($this->team);

    Readings::record($this->production, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record($this->staging);

    $response = wallPage($member, $this->team)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('page.environments', 1)
        ->where('page.environments.0.id', $this->staging->slug)
        ->where('page.kpis.openAnomalies', 0)
        ->where('page.kpis.openAlertSeverity', null)->where('page.kpis.environmentsNeedingAttention', 0)->where('page.kpis.environmentsJudged', 1)
        ->has('page.anomalies', 0));

    expect($response->getContent())->not->toContain($this->production->slug);
});

test('an environment never read is on the wall without a status, and is not counted as healthy', function () {
    Readings::record($this->production);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.id', $this->staging->slug)
            ->where('page.environments.0.status', null)
            ->where('page.environments.0.lastReadingAt', null)
            ->where('page.kpis.environmentsHealthy', 1)->where('page.kpis.environmentsJudged', 1)
            ->has('page.environments', 2)
            ->where('page.kpis.openAnomalies', 0));
});

test('a row that will never be read stays below every row in trouble', function () {
    $quiet = Application::factory()->for($this->team)->create(['name' => 'Archive']);
    $waiting = Environment::factory()->for($quiet)->production()->create(['polling_enabled' => false]);
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueueMaxWait], snapshot: ['pending' => 0]);
    Readings::record($this->staging, snapshot: ['pending' => 900]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.id', $this->production->slug)
            ->where('page.environments.1.id', $waiting->slug)
            ->where('page.environments.1.status', null)
            ->where('page.environments.2.id', $this->staging->slug));
});

test('an application first appears on the wall at its worst environment', function () {
    $other = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $otherDown = Environment::factory()->for($other)->staging()->create();
    $otherBusy = Environment::factory()->for($other)->production()->create();
    Readings::record($otherDown, EnvironmentStatus::Unreachable);
    Readings::record($otherBusy, snapshot: ['pending' => 1900]);
    Readings::record($this->production, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused], snapshot: ['pending' => 1]);
    Readings::record($this->staging, snapshot: ['pending' => 1800]);

    $response = wallPage($this->admin, $this->team);
    $rows = collect($response->inertiaProps('page.environments'));

    $firstByApplication = $rows->unique('applicationId')->pluck('id')->all();

    expect($firstByApplication)->toBe([$otherDown->slug, $this->production->slug])
        ->and($rows->pluck('id')->all())->toBe([
            $otherDown->slug,
            $this->production->slug,
            $otherBusy->slug,
            $this->staging->slug,
        ]);
});

test('healthy means active, on the desktop and on the phone alike', function () {
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending]);
    Readings::record($this->staging);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.environmentsHealthy', 1)
            ->where('page.kpis.environmentsNeedingAttention', 1)
            ->where('page.kpis.environmentsJudged', 2)
            ->missing('page.kpis.environmentsUp')
            ->missing('page.kpis.environmentsActive'));
});

test('the failed KPI names the window the environments share', function (int $production, int $staging, ?int $expected) {
    Readings::record($this->production, snapshot: ['failed_in_window' => 30, 'failed_window_minutes' => $production]);
    Readings::record($this->staging, snapshot: ['failed_in_window' => 12, 'failed_window_minutes' => $staging]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.failedWindowMinutes', $expected)
            ->where('page.kpis.failedInWindow', 42));
})->with([
    'both a day' => [1440, 1440, 1440],
    'both a week' => [10080, 10080, 10080],
    'both an hour' => [60, 60, 60],
    'a day and a week' => [1440, 10080, null],
]);

test('an environment not read yet does not make the failed window mixed', function () {
    Readings::record($this->production, snapshot: ['failed_window_minutes' => 1440]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.id', $this->staging->slug)
            ->where('page.environments.0.failedWindowMinutes', 10080)
            ->where('page.kpis.failedWindowMinutes', 1440));
});

test('with nothing read yet the failed window is the default the rows carry', function () {
    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page->where('page.kpis.failedWindowMinutes', 10080));
});

test('the failed KPI is the last hour, toned by the rules it breaks, with the window count beside it', function () {
    Readings::record($this->production, snapshot: ['pending' => 200, 'failed_in_window' => 5000, 'failed_window_minutes' => 60, 'failed_last_hour' => 3]);
    Readings::record($this->staging, snapshot: ['pending' => 100, 'failed_in_window' => 21, 'failed_window_minutes' => 10080, 'failed_last_hour' => 21]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.failedLastHour', 24)
            ->where('page.kpis.failedLastHourCapped', false)
            ->where('page.kpis.failedSeverity', 'warning')
            ->where('page.kpis.failedInWindow', 5021)
            ->missing('page.kpis.failedTotal')
            ->missing('page.kpis.environmentsOverFailedRate')
            ->where('page.environments.0.failedLastHour', 3)
            ->where('page.environments.1.failedLastHour', 21));
});

test('a last hour exactly at the threshold does not tone the failed KPI, as the evaluator decides', function () {
    Readings::record($this->production, snapshot: ['failed_last_hour' => 20]);
    Readings::record($this->staging, snapshot: ['failed_last_hour' => 3]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.failedLastHour', 23)
            ->where('page.kpis.failedSeverity', null));
});

test('an anomaly open for more than a day shows its full age', function () {
    Readings::record($this->production, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused]);
    Alert::query()->update(['opened_at' => now()->subHours(30)]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.anomalies', 1)
            ->where('page.anomalies.0.metric', 'horizon.paused')
            ->where('page.anomalies.0.minutesAgo', 1800));
});

test('the poll reload answers with only the props it asks for, and with the new reading', function () {
    Readings::record($this->production, snapshot: ['pending' => 500]);
    $state = Readings::record($this->staging, snapshot: ['pending' => 5]);

    $this->actingAs($this->admin)->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.environments.1.pending', 5));

    $this->travel(20)->seconds();
    EnvironmentSnapshot::factory()->for($this->staging)->create(['pending' => 9, 'captured_at' => now()]);
    $state->update(['captured_at' => now()]);

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'monitoring/Wall',
        'X-Inertia-Partial-Data' => 'page,openAlertCount',
    ])->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertJsonPath('props.page.environments.1.pending', 9)
        ->assertJsonPath('props.openAlertCount', 0)
        ->assertJsonMissingPath('props.teams')
        ->assertJsonMissingPath('props.canManageApplications');
});

test('filters in the query string do not change what the server sends', function () {
    Readings::record($this->production, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record($this->staging);

    $this->actingAs($this->admin)
        ->get(route('wall', ['current_team' => $this->team->slug, 'filter' => 'problems', 'environment' => 'production', 'q' => 'nothing-matches']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Wall')
            ->has('page.environments', 2));
});

test('the wall lists the most severe environments first, then the busiest', function () {
    $other = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $environments = [
        [$this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], 40],
        [$this->staging, EnvironmentStatus::Active, [], 900],
        [Environment::factory()->for($other)->production()->create(), EnvironmentStatus::Active, [], 30],
        [Environment::factory()->for($other)->staging()->create(), EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused], 5],
        [Environment::factory()->for($other)->preprod()->create(), EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], 400],
    ];

    foreach ($environments as [$environment, $status, $breaches, $pending]) {
        Readings::record($environment, $status, $breaches, snapshot: ['pending' => $pending]);
    }

    $this->actingAs($this->admin);

    $rows = app(WallQuery::class)->handle($this->team, $this->admin)->environments;

    expect(array_map(fn ($row) => [$row->status->value, $row->pending], $rows))->toBe([
        ['paused', 5],
        ['degraded', 400],
        ['degraded', 40],
        ['active', 900],
        ['active', 30],
    ]);
});

test('a paused collection sorts with the calm rows, whatever its last status', function () {
    $other = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $paused = Environment::factory()->for($other)->production()->create(['polling_enabled' => false]);
    Readings::record($paused, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending]);
    Readings::record($this->staging);

    $this->actingAs($this->admin);

    $rows = app(WallQuery::class)->handle($this->team, $this->admin)->environments;

    expect(array_map(fn ($row) => $row->id, $rows))->toBe([
        $this->production->slug,
        $paused->slug,
        $this->staging->slug,
    ]);
});

test('the wall KPIs count only the environments it judges', function () {
    $other = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $paused = Environment::factory()->for($other)->production()->create(['polling_enabled' => false]);
    Environment::factory()->for($other)->staging()->create();
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: ['pending' => 40, 'failed_last_hour' => 25, 'failed_in_window' => 300, 'jobs_per_minute' => 30]);
    Readings::record($this->staging, snapshot: ['pending' => 10, 'failed_last_hour' => 2, 'failed_in_window' => 7, 'jobs_per_minute' => 12]);
    Readings::record($paused, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive], snapshot: ['pending' => 900, 'failed_last_hour' => 99, 'failed_in_window' => 999, 'failed_window_minutes' => 10080, 'jobs_per_minute' => 500]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.jobsPerMinute', 42)
            ->has('page.environments', 4)
            ->where('page.kpis.environmentsJudged', 2)
            ->where('page.kpis.environmentsHealthy', 1)
            ->where('page.kpis.environmentsNeedingAttention', 1)
            ->where('page.kpis.pendingTotal', 50)
            ->where('page.kpis.failedLastHour', 27)
            ->where('page.kpis.failedSeverity', 'warning')
            ->where('page.kpis.failedInWindow', 307)
            ->where('page.kpis.failedWindowMinutes', 1440));
});

test('the failed KPI takes the severity of the worst rule it breaks and says when it is a lower bound', function () {
    AlertRule::factory()->for($this->team)->forScope('production')->inheriting()->create([
        'metric' => AlertRuleMetric::JobsFailedPerHour,
        'severity' => AlertSeverity::Critical,
    ]);
    Readings::record($this->production, snapshot: ['failed_last_hour' => 21]);
    Readings::record($this->staging, snapshot: ['failed_last_hour' => 21, 'failed_last_hour_capped' => true]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.failedLastHour', 42)
            ->where('page.kpis.failedLastHourCapped', true)
            ->where('page.kpis.failedSeverity', 'critical'));
});

test('the open alerts KPI takes the severity of the worst open alert', function (EnvironmentStatus $status, ?AlertRuleMetric $metric, ?string $severity) {
    Readings::record($this->production, $status, $metric === null ? [] : [$metric]);
    Readings::record($this->staging);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page->where('page.kpis.openAlertSeverity', $severity));
})->with([
    'a paused horizon warns' => [EnvironmentStatus::Paused, AlertRuleMetric::HorizonPaused, 'warning'],
    'an inactive horizon is critical' => [EnvironmentStatus::Inactive, AlertRuleMetric::HorizonMasterInactive, 'critical'],
    'nothing open has no tone' => [EnvironmentStatus::Active, null, null],
]);
