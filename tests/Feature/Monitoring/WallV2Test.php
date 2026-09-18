<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
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
            ->has('page.environments', 2)
            ->where('page.environments.0.id', $this->production->slug)
            ->where('page.environments.0.status', 'degraded')
            ->where('page.environments.1.id', $this->staging->slug)
            ->where('page.environments.1.applicationId', $this->application->slug)
            ->where('page.environments.1.applicationName', 'Invoicer')
            ->where('page.environments.1.status', 'active')
            ->where('page.environments.1.color', 'staging')
            ->where('page.environments.1.pending', 200)
            ->where('page.environments.1.trend', [0, 0, 0, 0, 0, 0, 100, 100, 100, 200, 200, 200])
            ->where('page.environments.1.trendPercent', 100)
            ->where('page.environments.1.thresholds', fn ($thresholds) => (float) $thresholds['jobs.failed_per_hour'] === AlertRuleMetric::JobsFailedPerHour->defaultThreshold()));
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
        ->where('page.kpis.environmentsTotal', 1)
        ->where('page.kpis.openAnomalies', 0)
        ->has('page.anomalies', 0));

    expect($response->getContent())->not->toContain($this->production->slug);
});

test('an environment never read is on the wall without a status, and is not counted as up', function () {
    Readings::record($this->production);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.id', $this->staging->slug)
            ->where('page.environments.0.status', null)
            ->where('page.environments.0.lastReadingAt', null)
            ->where('page.kpis.environmentsUp', 1)
            ->where('page.kpis.environmentsTotal', 2)
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

test('the phone counts only active environments as up', function () {
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending]);
    Readings::record($this->staging);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.environmentsUp', 2)
            ->where('page.kpis.environmentsActive', 1));
});

test('the failed KPI names the window the environments share', function (int $production, int $staging, ?int $expected) {
    Readings::record($this->production, snapshot: ['failed_in_window' => 30, 'failed_window_minutes' => $production]);
    Readings::record($this->staging, snapshot: ['failed_in_window' => 12, 'failed_window_minutes' => $staging]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.failedWindowMinutes', $expected)
            ->where('page.kpis.failedTotal', 42));
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

test('the failed warning counts environments over the last hour, not over the window count', function () {
    Readings::record($this->production, snapshot: ['pending' => 200, 'failed_in_window' => 5000, 'failed_window_minutes' => 60, 'failed_last_hour' => 3]);
    Readings::record($this->staging, snapshot: ['pending' => 100, 'failed_in_window' => 21, 'failed_window_minutes' => 10080, 'failed_last_hour' => 21]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.kpis.environmentsOverFailedRate', 1)
            ->where('page.kpis.failedTotal', 5021)
            ->where('page.environments.0.failedLastHour', 3)
            ->where('page.environments.1.failedLastHour', 21));
});

test('a last hour exactly at the threshold does not warn, as the evaluator decides', function () {
    Readings::record($this->production, snapshot: ['failed_last_hour' => 20]);
    Readings::record($this->staging, snapshot: ['failed_last_hour' => 21]);

    wallPage($this->admin, $this->team)
        ->assertInertia(fn (Assert $page) => $page->where('page.kpis.environmentsOverFailedRate', 1));
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
