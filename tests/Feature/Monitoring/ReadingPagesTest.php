<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\ReadingError;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->freezeTime();
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam;
    $this->application = Application::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->application)->production()->create([
        'basic_auth_password' => 'never-shown-7f3a',
        'poll_interval_seconds' => 30,
    ]);
    $this->actingAs($this->user);
});

function readingEnvironmentUrl(Environment $environment): string
{
    return route('environments.show', ['current_team' => $environment->application->team->slug, 'environment' => $environment->slug]);
}

function readingApplicationUrl(Application $application): string
{
    return route('applications.show', ['current_team' => $application->team->slug, 'application' => $application->slug]);
}

function readingTeamMember(User $owner, TeamRole $role): User
{
    $user = User::factory()->create();
    $owner->currentTeam->members()->attach($user, [
        'role' => $role->value,
        'visibility' => MemberVisibility::All->value,
    ]);
    $user->switchTeam($owner->currentTeam);

    return $user;
}

test('a fresh reading reaches the environment page with its age, interval and node sightings', function () {
    Readings::record($this->environment, snapshot: ['latency_ms' => 84, 'failed_last_24_hours' => 12], state: [
        'captured_at' => now()->subSeconds(5),
        'latency_ms' => 84,
        'nodes' => [
            ['hostname' => 'worker-1.example.com', 'status' => 'running', 'workers' => 6, 'supervisors' => 2, 'queues' => 3, 'seenAt' => now()->subSeconds(40)->toIso8601String()],
        ],
    ]);

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.status', 'active')
            ->where('page.environment.lastReadingAt', now()->subSeconds(5)->toIso8601String())
            ->where('page.environment.stale', false)
            ->where('page.environment.pollingEnabled', true)
            ->where('page.environment.pollIntervalSeconds', 30)
            ->where('page.environment.readingError', null)
            ->where('page.environment.latencyMs', 84)
            ->where('page.environment.failedLast24Hours', 12)
            ->where('page.environment.failedWindowMinutes', 1440)
            ->where('page.environment.basicAuthUser', 'monitor')
            ->where('page.nodes.0.seenSecondsAgo', 40)
            ->where('page.nodes.0.supervisorCount', 2)
            ->where('page.nodes.0.queueCount', 3)
            ->missing('page.nodes.0.memoryMb')
            ->missing('page.nodes.0.lastHeartbeatSecondsAgo')
            ->missing('page.nodes.0.jobsPerMinute')
            ->missing('page.environment.redisMemoryGb')
            // The queue whose runtime Horizon does not record stays null.
            ->where('page.queues.2.runtimeSeconds', null)
            ->where('page.thresholds', fn ($thresholds) => (float) $thresholds['jobs.failed_per_hour'] === AlertRuleMetric::JobsFailedPerHour->defaultThreshold()
                && (float) $thresholds['job.runtime'] === AlertRuleMetric::JobRuntime->defaultThreshold())
            ->where('page.canTestConnection', true));
});

test('the page thresholds are the defaults, not the invented scope overrides', function () {
    // production carries a phase-1 override of queue.pending (5000): the
    // anomalies still use 2000, and so must the page's colours.
    Readings::record($this->environment);

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.rules', fn ($rules) => (float) collect($rules)->firstWhere('metric', 'queue.pending')['threshold'] === 5000.0)
            ->where('page.thresholds', fn ($thresholds) => (float) $thresholds['queue.pending'] === 2000.0));
});

test('an old reading is flagged as not updated', function () {
    // Thirty-second interval: ten minutes without a reading is far past it.
    Readings::record($this->environment, snapshot: ['captured_at' => now()->subMinutes(10)]);

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.stale', true)
            ->where('page.environment.status', 'active')
            ->where('page.environment.lastReadingAt', now()->subMinutes(10)->toIso8601String()));
});

test('a paused collection is never stale and keeps its last reading', function () {
    $this->environment->update(['polling_enabled' => false]);
    Readings::record($this->environment, snapshot: ['captured_at' => now()->subHours(3)]);

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.pollingEnabled', false)
            ->where('page.environment.stale', false)
            ->where('page.environment.lastReadingAt', now()->subHours(3)->toIso8601String()));
});

test('a failed reading carries its reason, zero counters and the last known detail with its age', function () {
    Readings::record(
        $this->environment,
        EnvironmentStatus::Unreachable,
        snapshot: ['error' => ReadingError::Unauthorized],
        state: [
            'error' => ReadingError::Unauthorized,
            'nodes' => [
                ['hostname' => 'worker-1.example.com', 'status' => 'running', 'workers' => 6, 'supervisors' => 2, 'queues' => 3, 'seenAt' => now()->subMinutes(7)->toIso8601String()],
            ],
        ],
    );

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.status', 'unreachable')
            ->where('page.environment.readingError', 'unauthorized')
            ->where('page.environment.latencyMs', null)
            ->where('page.environment.pending', 0)
            ->where('page.environment.workers', 0)
            // The detail of the last reading that worked, dated by it.
            ->where('page.nodes.0.seenSecondsAgo', 420)
            ->where('page.nodes.0.status', 'unreachable')
            ->has('page.queues', 3)
            ->where('page.longRunningJobs', [])
            ->where('page.openAlert.metric', 'endpoint.unreachable'));
});

test('an environment without readings says nothing yet', function () {
    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.status', null)
            ->where('page.environment.lastReadingAt', null)
            ->where('page.environment.readingError', null)
            ->where('page.environment.stale', false)
            ->where('page.environment.latencyMs', null)
            ->where('page.nodes', [])
            ->where('page.queues', [])
            ->where('page.openAlert', null));

    $this->get(readingApplicationUrl($this->application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.status', null)
            ->where('page.environments.0.watched', true)
            ->where('page.worstStatus', null));
});

test('a seven-day failed window is carried to every page that shows the count', function () {
    Readings::record($this->environment, snapshot: ['failed_last_24_hours' => 300, 'failed_window_minutes' => 10080]);

    $this->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.failedLast24Hours', 300)
            ->where('page.environment.failedWindowMinutes', 10080));

    $this->get(readingApplicationUrl($this->application))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environments.0.failedLast24Hours', 300)
            ->where('page.environments.0.failedWindowMinutes', 10080)
            ->where('page.failedPerHourThreshold', fn ($threshold) => (float) $threshold === AlertRuleMetric::JobsFailedPerHour->defaultThreshold()));

    $this->get(route('applications.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups.0.environments.0.failedWindowMinutes', 10080));
});

test('the application page carries the recent anomalies with their start', function () {
    Readings::record($this->environment, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused]);

    $this->get(readingApplicationUrl($this->application))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('page.cards')
            ->where('page.worstStatus', 'paused')
            ->where('page.recentAlerts.0.metric', 'horizon.paused')
            ->where('page.recentAlerts.0.sinceTruncated', false)
            ->where('page.recentAlerts.0.minutesAgo', 0));
});

test('the application page does not query once per environment', function () {
    $count = function (Application $application): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(readingApplicationUrl($application))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    Readings::record($this->environment);
    // The first request of a test pays for things the others do not.
    $count($this->application);
    $one = $count($this->application);

    foreach (['staging', 'develop', 'demo'] as $state) {
        Readings::record(Environment::factory()->for($this->application)->{$state}()->create());
    }

    // Four environments, their states and trends, in the same number of
    // queries as one: no series per card any more.
    expect($count($this->application))->toBe($one);
});

test('only people who may test a connection get the button', function (TeamRole $role, bool $allowed) {
    Readings::record($this->environment);

    $this->actingAs(readingTeamMember($this->user, $role))
        ->get(readingEnvironmentUrl($this->environment))
        ->assertInertia(fn (Assert $page) => $page->where('page.canTestConnection', $allowed));
})->with([
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, true],
    'viewer' => [TeamRole::Viewer, false],
]);

test('no reading page carries the stored password', function (string $page) {
    Readings::record($this->environment);

    $url = match ($page) {
        'environment' => readingEnvironmentUrl($this->environment),
        'application' => readingApplicationUrl($this->application),
        'applications' => route('applications.index', ['current_team' => $this->team->slug]),
    };

    $this->get($url)
        ->assertOk()
        ->assertDontSee('never-shown-7f3a', false)
        ->assertInertia(fn (Assert $assert) => $assert->has('page'));
})->with(['environment', 'application', 'applications']);
