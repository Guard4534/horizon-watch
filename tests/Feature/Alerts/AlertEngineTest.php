<?php

use App\Actions\Monitoring\PollEnvironment;
use App\Alerts\AlertEngine;
use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentColor;
use App\Enums\MemberVisibility;
use App\Enums\ReadingError;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonProbe;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonReading;
use App\Externals\Horizon\HorizonTarget;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
    Event::fake([AlertOpened::class, AlertResolved::class]);

    $this->reader = new class implements HorizonReader
    {
        /** @var list<HorizonReading|Throwable|Closure(): HorizonReading> */
        public array $results = [];

        public function read(HorizonTarget $target): HorizonReading
        {
            $result = array_shift($this->results) ?? throw new LogicException('No reading queued.');

            if ($result instanceof Closure) {
                $result = $result();
            }

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        public function probe(HorizonTarget $target): HorizonProbe
        {
            throw new LogicException('The poller never probes.');
        }
    };

    $this->app->instance(HorizonReader::class, $this->reader);

    $this->application = Application::factory()->create(['name' => 'Billing']);
    $this->environment = Environment::factory()->for($this->application)->production()->create();
    $this->team = $this->application->team;

    $this->reading = fn (array $overrides = []): HorizonReading => new HorizonReading(...array_merge([
        'stats' => new HorizonStats(
            status: 'running',
            jobsPerMinute: 120,
            failedJobs: 5,
            processes: 12,
            pausedMasters: 0,
            wait: [],
            failedJobsPeriodMinutes: 1440,
        ),
        'masters' => [new HorizonMaster(name: 'worker-1.example.com', status: 'running', supervisors: [])],
        'workload' => [new HorizonQueueLoad(name: 'default', length: 12, wait: 2, processes: 7)],
        'failedJobs' => [],
        'pendingJobs' => [],
        'queueRuntimes' => [],
        'latencyMs' => 42,
    ], $overrides));

    $this->pending = fn (int $length): HorizonReading => ($this->reading)([
        'workload' => [new HorizonQueueLoad(name: 'default', length: $length, wait: 2, processes: 7)],
    ]);

    $this->inactive = fn (): HorizonReading => ($this->reading)([
        'stats' => new HorizonStats(status: 'inactive', jobsPerMinute: 0, failedJobs: 0, processes: 0, pausedMasters: 0, wait: [], failedJobsPeriodMinutes: 1440),
        'masters' => [],
    ]);

    $this->paused = fn (): HorizonReading => ($this->reading)([
        'stats' => new HorizonStats(status: 'paused', jobsPerMinute: 0, failedJobs: 0, processes: 0, pausedMasters: 1, wait: [], failedJobsPeriodMinutes: 1440),
    ]);

    $this->unreachable = fn (): HorizonReadFailed => new HorizonReadFailed(ReadingError::Unreachable);

    $this->poll = function (HorizonReading|Throwable|Closure ...$results): void {
        $this->reader->results = $results;

        foreach ($results as $ignored) {
            app()->forgetScopedInstances();
            app(PollEnvironment::class)->handle($this->environment);
        }
    };

    $this->rule = fn (string $scope, AlertRuleMetric $metric, array $values): AlertRule => AlertRule::factory()->for($this->team)->create([
        'scope' => $scope,
        'metric' => $metric,
        'threshold' => null,
        'severity' => null,
        'notify_email' => null,
        'enabled' => null,
        ...$values,
    ]);
});

test('a breached threshold opens one alert with the environment and rule copied onto it', function () {
    ($this->poll)(($this->pending)(2500));

    $alert = Alert::query()->sole();

    expect($alert->team_id)->toBe($this->team->id)
        ->and($alert->environment_id)->toBe($this->environment->id)
        ->and($alert->metric)->toBe(AlertRuleMetric::QueuePending)
        ->and($alert->severity)->toBe(AlertSeverity::Warning)
        ->and($alert->application_name)->toBe('Billing')
        ->and($alert->environment_name)->toBe('production')
        ->and($alert->environment_color)->toBe(EnvironmentColor::Prod)
        ->and($alert->threshold)->toBe(2000.0)
        ->and($alert->unit)->toBe('job')
        ->and($alert->value)->toBe(2500.0)
        ->and($alert->detail)->toBe(['pending' => 2500])
        ->and($alert->opened_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($alert->resolved_at)->toBeNull()
        ->and($alert->notified)->toBeFalse();

    Event::assertDispatchedTimes(AlertOpened::class, 1);
    Event::assertDispatched(AlertOpened::class, fn (AlertOpened $event) => $event->alertId === $alert->id);
    Event::assertNotDispatched(AlertResolved::class);
});

test('a healthy reading opens nothing', function () {
    ($this->poll)(($this->reading)());

    expect(Alert::query()->count())->toBe(0);
    Event::assertNothingDispatched();
});

test('a reading that still breaches updates the open alert instead of opening another', function () {
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(3100));

    $alert = Alert::query()->sole();

    expect($alert->opened_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($alert->value)->toBe(3100.0)
        ->and($alert->detail)->toBe(['pending' => 3100]);

    Event::assertDispatchedTimes(AlertOpened::class, 1);
});

test('the first successful reading under the threshold resolves the alert', function () {
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(10));

    $alert = Alert::query()->sole();

    expect($alert->resolved_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:00');

    Event::assertDispatchedTimes(AlertResolved::class, 1);
    Event::assertDispatched(AlertResolved::class, fn (AlertResolved $event) => $event->alertId === $alert->id);
});

test('a breach after a resolution opens a new alert', function () {
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(10));
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(2600));

    expect(Alert::query()->count())->toBe(2)
        ->and(Alert::query()->open()->sole()->opened_at->toDateTimeString())->toBe('2026-09-17 10:00:30');
});

test('a failed reading neither opens nor resolves a threshold alert', function () {
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->unreachable)());

    $pending = Alert::query()->where('metric', AlertRuleMetric::QueuePending)->sole();

    expect($pending->resolved_at)->toBeNull()
        ->and($pending->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and(Alert::query()->count())->toBe(1);
});

test('a failed reading never opens a threshold alert, even with the breach stored on the snapshot', function () {
    $snapshot = EnvironmentSnapshot::factory()->for($this->environment)->failed()->create([
        'breaches' => [AlertRuleMetric::EndpointUnreachable, AlertRuleMetric::QueuePending],
    ]);
    $state = EnvironmentState::factory()->for($this->environment)->failed()->create(['status_since' => now()]);

    app(AlertEngine::class)->afterReading($this->environment, $snapshot, $state);

    expect(Alert::query()->count())->toBe(0);
});

test('a state that does not know its start counts its run from the reading', function () {
    ($this->rule)('organization', AlertRuleMetric::EndpointUnreachable, ['threshold' => 1]);
    $state = EnvironmentState::factory()->for($this->environment)->failed()->create(['status_since' => null]);
    $snapshot = EnvironmentSnapshot::factory()->for($this->environment)->failed()->create();

    app(AlertEngine::class)->afterReading($this->environment, $snapshot, $state);

    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    $later = EnvironmentSnapshot::factory()->for($this->environment)->failed()->create();
    $state->forceFill(['captured_at' => now(), 'status_since' => '2026-09-17 09:58:00']);
    app()->forgetScopedInstances();
    app(AlertEngine::class)->afterReading($this->environment, $later, $state);

    expect(Alert::query()->sole()->value)->toBe(3.0);
});

test('an unreachable environment opens its alert once the run lasts the minutes of the rule', function () {
    ($this->poll)(($this->unreachable)());
    $this->travel(119)->seconds();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->seconds();
    ($this->poll)(($this->unreachable)());

    $alert = Alert::query()->sole();

    expect($alert->metric)->toBe(AlertRuleMetric::EndpointUnreachable)
        ->and($alert->severity)->toBe(AlertSeverity::Critical)
        ->and($alert->threshold)->toBe(2.0)
        ->and($alert->unit)->toBe('min')
        ->and($alert->value)->toBe(2.0)
        ->and($alert->detail)->toBe([])
        ->and($alert->opened_at->toDateTimeString())->toBe('2026-09-17 10:02:00');

    $this->travel(3)->minutes();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->sole()->value)->toBe(5.0);
});

test('an unreachable alert resolves with the first reading that succeeds', function () {
    ($this->poll)(($this->unreachable)());
    $this->travel(2)->minutes();
    ($this->poll)(($this->unreachable)());
    $this->travel(15)->seconds();
    ($this->poll)(($this->reading)());

    expect(Alert::query()->sole()->resolved_at->toDateTimeString())->toBe('2026-09-17 10:02:15');
    Event::assertDispatchedTimes(AlertResolved::class, 1);
});

test('an inactive horizon opens after five minutes and resolves when the status changes', function () {
    ($this->poll)(($this->inactive)());
    $this->travel(4)->minutes();
    ($this->poll)(($this->inactive)());

    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    ($this->poll)(($this->inactive)());

    expect(Alert::query()->sole()->metric)->toBe(AlertRuleMetric::HorizonMasterInactive)
        ->and(Alert::query()->sole()->severity)->toBe(AlertSeverity::Critical);

    $this->travel(15)->seconds();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->sole()->resolved_at->toDateTimeString())->toBe('2026-09-17 10:05:15');
});

test('a paused horizon opens after fifteen minutes and resolves when it runs again', function () {
    ($this->poll)(($this->paused)());
    $this->travel(15)->minutes();
    ($this->poll)(($this->paused)());

    expect(Alert::query()->sole()->metric)->toBe(AlertRuleMetric::HorizonPaused)
        ->and(Alert::query()->sole()->severity)->toBe(AlertSeverity::Warning);

    $this->travel(15)->seconds();
    ($this->poll)(($this->reading)());

    expect(Alert::query()->sole()->resolved_at)->not->toBeNull();
});

test('the minutes of a state rule come from the effective rule', function () {
    ($this->rule)('organization', AlertRuleMetric::EndpointUnreachable, ['threshold' => 10]);

    ($this->poll)(($this->unreachable)());
    $this->travel(9)->minutes();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->sole()->threshold)->toBe(10.0);
});

test('a state that changed resets the run, so the alert waits the minutes again', function () {
    ($this->poll)(($this->inactive)());
    $this->travel(4)->minutes();
    ($this->poll)(($this->reading)());
    $this->travel(2)->minutes();
    ($this->poll)(($this->inactive)());

    expect(Alert::query()->count())->toBe(0);
});

test('a disabled rule opens nothing', function (AlertRuleMetric $metric, Closure $reading) {
    ($this->rule)('organization', $metric, ['enabled' => false]);

    ($this->poll)($reading->call($this));
    $this->travel(30)->minutes();
    ($this->poll)($reading->call($this));

    expect(Alert::query()->count())->toBe(0);
})->with([
    'threshold rule' => [AlertRuleMetric::QueuePending, fn () => ($this->pending)(2500)],
    'state rule' => [AlertRuleMetric::HorizonMasterInactive, fn () => ($this->inactive)()],
    'unreachable' => [AlertRuleMetric::EndpointUnreachable, fn () => ($this->unreachable)()],
]);

test('disabling a rule resolves its open alert at the next reading', function (AlertRuleMetric $metric, Closure $reading) {
    ($this->poll)($reading->call($this));
    $this->travel(30)->minutes();
    ($this->poll)($reading->call($this));

    expect(Alert::query()->open()->count())->toBe(1);

    ($this->rule)('production', $metric, ['enabled' => false]);
    $this->travel(15)->seconds();
    ($this->poll)($reading->call($this));

    expect(Alert::query()->open()->count())->toBe(0);
    Event::assertDispatchedTimes(AlertResolved::class, 1);
})->with([
    'threshold rule' => [AlertRuleMetric::QueuePending, fn () => ($this->pending)(2500)],
    'state rule' => [AlertRuleMetric::HorizonMasterInactive, fn () => ($this->inactive)()],
]);

test('a disabled threshold rule keeps its alert open through failed readings', function () {
    ($this->poll)(($this->pending)(2500));
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['enabled' => false]);
    $this->travel(15)->seconds();
    ($this->poll)(($this->unreachable)());

    expect(Alert::query()->where('metric', AlertRuleMetric::QueuePending)->sole()->resolved_at)->toBeNull();
});

test('the rule of the environment name decides threshold and severity at opening', function () {
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['threshold' => 100]);
    ($this->rule)('Production', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical]);

    ($this->poll)(($this->pending)(150));

    $alert = Alert::query()->sole();

    expect($alert->threshold)->toBe(100.0)
        ->and($alert->severity)->toBe(AlertSeverity::Critical);
});

test('a change of severity reaches the open alert at the next reading', function () {
    ($this->poll)(($this->pending)(2500));

    expect(Alert::query()->sole()->severity)->toBe(AlertSeverity::Warning);

    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical, 'threshold' => 2200]);
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(2500));

    $alert = Alert::query()->sole();

    expect($alert->severity)->toBe(AlertSeverity::Critical)
        ->and($alert->threshold)->toBe(2200.0);
    Event::assertDispatchedTimes(AlertOpened::class, 1);
});

test('each threshold rule stores its own value and detail', function (array $overrides, AlertRuleMetric $metric, float $value, array $detail) {
    ($this->poll)(($this->reading)($overrides));

    $alert = Alert::query()->where('metric', $metric)->sole();

    expect($alert->value)->toBe($value)
        ->and($alert->detail)->toBe($detail);
})->with([
    'max wait' => [
        ['workload' => [new HorizonQueueLoad(name: 'default', length: 12, wait: 95, processes: 7), new HorizonQueueLoad(name: 'mail', length: 1, wait: 70, processes: 1)]],
        AlertRuleMetric::QueueMaxWait,
        95.0,
        ['waitSeconds' => 95],
    ],
    'failed per hour' => [
        ['failedJobs' => array_map(fn (int $minute) => new HorizonFailedJob(
            name: 'App\\Jobs\\SendInvoiceEmail',
            queue: 'emails',
            exception: 'RuntimeException: The mail server did not answer',
            attempts: 3,
            failedAt: CarbonImmutable::parse('2026-09-17 10:00:00')->subMinutes($minute),
        ), range(1, 21))],
        AlertRuleMetric::JobsFailedPerHour,
        21.0,
        ['failed' => 21],
    ],
    'job runtime' => [
        ['pendingJobs' => [
            new HorizonPendingJob(name: 'App\\Jobs\\ShortOne', queue: 'default', status: 'reserved', reservedAt: CarbonImmutable::parse('2026-09-17 09:57:00')),
            new HorizonPendingJob(name: 'App\\Jobs\\BuildReport', queue: 'reports', status: 'reserved', reservedAt: CarbonImmutable::parse('2026-09-17 09:50:00')),
            new HorizonPendingJob(name: 'App\\Jobs\\Waiting', queue: 'default', status: 'pending', reservedAt: null),
        ]],
        AlertRuleMetric::JobRuntime,
        600.0,
        ['job' => 'App\\Jobs\\BuildReport', 'queue' => 'reports', 'seconds' => 600],
    ],
    'workers missing' => [
        ['workload' => array_map(
            fn (int $index) => new HorizonQueueLoad(name: "queue-{$index}", length: 3, wait: 1, processes: 0),
            range(1, 12),
        )],
        AlertRuleMetric::WorkersMissing,
        12.0,
        ['queues' => array_map(fn (int $index) => "queue-{$index}", range(1, 10))],
    ],
]);

test('a paused horizon still opens the thresholds it breaks at once', function () {
    ($this->poll)(($this->reading)([
        'stats' => new HorizonStats(status: 'paused', jobsPerMinute: 0, failedJobs: 0, processes: 0, pausedMasters: 1, wait: [], failedJobsPeriodMinutes: 1440),
        'workload' => [new HorizonQueueLoad(name: 'default', length: 5000, wait: 2, processes: 7)],
    ]));

    expect(Alert::query()->pluck('metric')->all())->toBe([AlertRuleMetric::QueuePending]);
});

test('two openings of the same rule leave a single open alert and a single event', function () {
    $competitor = null;

    DB::listen(function (QueryExecuted $query) use (&$competitor) {
        if ($competitor === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "alerts"')) {
            $competitor = Alert::factory()->for($this->environment)->create([
                'team_id' => $this->team->id,
                'metric' => AlertRuleMetric::QueuePending,
            ]);
        }
    });

    ($this->poll)(($this->pending)(2500));

    expect(Alert::query()->open()->count())->toBe(1)
        ->and(Alert::query()->sole()->id)->toBe($competitor->id);
    Event::assertNotDispatched(AlertOpened::class);
});

test('a late reading leaves the alerts alone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15'));
    ($this->poll)(($this->pending)(2500));

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
    ($this->poll)(($this->pending)(10));

    $alert = Alert::query()->sole();

    expect($alert->resolved_at)->toBeNull()
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($alert->value)->toBe(2500.0);
});

test('a reading dropped because the address changed leaves the alerts alone', function () {
    ($this->poll)(function () {
        Environment::query()->whereKey($this->environment->id)->update(['horizon_url' => 'https://preprod.example.com/horizon']);

        return ($this->pending)(2500);
    });

    expect(Alert::query()->count())->toBe(0);
});

test('the alerts of other environments are left alone', function () {
    $other = Environment::factory()->for($this->application)->staging()->create();
    $foreign = Alert::factory()->for($other)->create(['team_id' => $this->team->id]);

    ($this->poll)(($this->reading)());

    expect($foreign->fresh()->resolved_at)->toBeNull();
});

test('resolving everything of an environment dispatches nothing and spares the others', function () {
    $other = Environment::factory()->for($this->application)->staging()->create();
    $open = Alert::factory()->for($this->environment)->count(2)->sequence(
        ['metric' => AlertRuleMetric::QueuePending],
        ['metric' => AlertRuleMetric::QueueMaxWait],
    )->create();
    $resolved = Alert::factory()->for($this->environment)->resolved()->create(['resolved_at' => now()->subDay()]);
    $foreign = Alert::factory()->for($other)->create();

    app(AlertEngine::class)->resolveAllFor($this->environment, CarbonImmutable::parse('2026-09-17 10:30:00'));

    expect($open->map(fn (Alert $alert) => $alert->fresh()->resolved_at->toDateTimeString())->all())
        ->toBe(['2026-09-17 10:30:00', '2026-09-17 10:30:00'])
        ->and($resolved->fresh()->resolved_at->toDateTimeString())->toBe('2026-09-16 10:00:00')
        ->and($foreign->fresh()->resolved_at)->toBeNull();
    Event::assertNothingDispatched();
});

test('the alert id is a uuid', function () {
    ($this->poll)(($this->pending)(2500));

    expect(Str::isUuid(Alert::query()->sole()->id))->toBeTrue();
});

test('deleting an environment resolves its open alerts quietly and keeps their history readable', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $open = Alert::factory()->for($this->environment)->create();
    $resolved = Alert::factory()->for($this->environment)->create(['resolved_at' => now()->subDay()]);
    $other = Alert::factory()->for(Environment::factory()->for($this->application)->staging())->create();
    $this->travel(5)->minutes();

    $this->actingAs($owner)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $this->environment->slug]), [
            'name' => $this->environment->name,
        ])
        ->assertRedirect();

    $open->refresh();

    expect($open->resolved_at->toDateTimeString())->toBe('2026-09-17 10:05:00')
        ->and($open->environment_id)->toBeNull()
        ->and([$open->application_name, $open->environment_name])->toBe(['Billing', 'production'])
        ->and($resolved->fresh()->resolved_at->toDateTimeString())->toBe('2026-09-16 10:00:00')
        ->and($other->fresh()->resolved_at)->toBeNull();
    Event::assertNothingDispatched();
});

test('a refused environment deletion leaves its alerts open', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $open = Alert::factory()->for($this->environment)->create();

    $this->actingAs($owner)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $this->environment->slug]), [
            'name' => 'wrong name',
        ])
        ->assertInvalid(['name']);

    expect($open->fresh()->resolved_at)->toBeNull();
});

test('deleting an application resolves the open alerts of every environment it had', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $staging = Environment::factory()->for($this->application)->staging()->create();
    $alerts = collect([
        Alert::factory()->for($this->environment)->create(),
        Alert::factory()->for($staging)->create(),
    ]);
    $elsewhere = Alert::factory()->for(Environment::factory()->for(Application::factory()->for($this->team)))->create();

    $this->actingAs($owner)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertRedirect();

    expect($alerts->map(fn (Alert $alert) => $alert->fresh()->resolved_at?->toDateTimeString())->all())
        ->toBe(['2026-09-17 10:00:00', '2026-09-17 10:00:00'])
        ->and($alerts->map(fn (Alert $alert) => $alert->fresh()->environment_id)->all())->toBe([null, null])
        ->and($elsewhere->fresh()->resolved_at)->toBeNull();
    Event::assertNothingDispatched();
});

test('deleting an organization removes its alerts, rules, settings and log, and nothing of the others', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $alert = Alert::factory()->for($this->environment)->create();
    AlertNotification::factory()->for($alert)->create();
    AlertNotification::factory()->create(['alert_id' => null, 'team_id' => $this->team->id, 'kind' => SentNotificationKind::Test]);
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['threshold' => 10]);
    NotificationSetting::factory()->for($this->team)->create();

    $survivor = Alert::factory()->for(Environment::factory())->create();
    AlertNotification::factory()->for($survivor)->create();
    AlertRule::factory()->for($survivor->team)->create();
    NotificationSetting::factory()->for($survivor->team)->create();

    $this->actingAs($owner)
        ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name])
        ->assertRedirect();

    $this->assertSoftDeleted('teams', ['id' => $this->team->id]);

    expect(Alert::query()->pluck('id')->all())->toBe([$survivor->id])
        ->and(AlertNotification::query()->pluck('team_id')->unique()->all())->toBe([$survivor->team_id])
        ->and(AlertRule::query()->pluck('team_id')->all())->toBe([$survivor->team_id])
        ->and(NotificationSetting::query()->pluck('team_id')->all())->toBe([$survivor->team_id]);
});

test('an inactive reading leaves the open threshold alerts as they are, since it judges no threshold', function () {
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical]);
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->reading)([
        'stats' => new HorizonStats(status: 'inactive', jobsPerMinute: 0, failedJobs: 0, processes: 0, pausedMasters: 0, wait: [], failedJobsPeriodMinutes: 1440),
        'masters' => [],
        'workload' => [new HorizonQueueLoad(name: 'default', length: 2500, wait: 2, processes: 0)],
    ]));

    $alert = Alert::query()->where('metric', AlertRuleMetric::QueuePending)->sole();

    expect($alert->resolved_at)->toBeNull()
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($alert->severity)->toBe(AlertSeverity::Critical);
    Event::assertNotDispatched(AlertResolved::class);
});

test('an inactive reading opens no threshold alert', function () {
    ($this->poll)(($this->reading)([
        'stats' => new HorizonStats(status: 'inactive', jobsPerMinute: 0, failedJobs: 0, processes: 0, pausedMasters: 0, wait: [], failedJobsPeriodMinutes: 1440),
        'masters' => [],
        'workload' => [new HorizonQueueLoad(name: 'default', length: 2500, wait: 200, processes: 0)],
    ]));

    expect(Alert::query()->count())->toBe(0);
});

test('the first active reading after an inactive one judges the thresholds again', function () {
    ($this->poll)(($this->pending)(2500));
    $this->travel(15)->seconds();
    ($this->poll)(($this->inactive)());
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(10));

    expect(Alert::query()->where('metric', AlertRuleMetric::QueuePending)->sole()->resolved_at->toDateTimeString())
        ->toBe('2026-09-17 10:00:30');
    Event::assertDispatchedTimes(AlertResolved::class, 1);
});

test('a notified alert keeps its severity when the rule is lowered, so its resolution is still told', function () {
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical]);
    ($this->poll)(($this->pending)(2500));
    Alert::query()->update(['notified' => true]);

    AlertRule::query()->update(['severity' => AlertSeverity::Warning]);
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(2600));

    $alert = Alert::query()->sole();

    expect($alert->severity)->toBe(AlertSeverity::Critical)
        ->and($alert->value)->toBe(2600.0)
        ->and($alert->last_seen_at->toDateTimeString())->toBe('2026-09-17 10:00:15');
});

test('an alert not notified yet follows a lowered severity', function () {
    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical]);
    ($this->poll)(($this->pending)(2500));

    AlertRule::query()->update(['severity' => AlertSeverity::Warning]);
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(2600));

    expect(Alert::query()->sole()->severity)->toBe(AlertSeverity::Warning);
});

test('a notified warning is still raised to critical', function () {
    ($this->poll)(($this->pending)(2500));
    Alert::query()->update(['notified' => true]);

    ($this->rule)('organization', AlertRuleMetric::QueuePending, ['severity' => AlertSeverity::Critical]);
    $this->travel(15)->seconds();
    ($this->poll)(($this->pending)(2600));

    expect(Alert::query()->sole()->severity)->toBe(AlertSeverity::Critical);
});

test('the long job of a reading is judged at the instant the reading was taken', function (string $reservedAt, ?float $value) {
    $job = new HorizonPendingJob(name: 'App\\Jobs\\BuildReport', queue: 'reports', status: 'reserved', reservedAt: CarbonImmutable::parse($reservedAt));

    ($this->poll)(function () use ($job) {
        $this->travel(4)->seconds();

        return ($this->reading)(['pendingJobs' => [$job]]);
    });

    $alert = Alert::query()->where('metric', AlertRuleMetric::JobRuntime)->first();

    expect($alert?->value)->toBe($value);

    if ($alert !== null) {
        expect($alert->value)->toBeGreaterThan($alert->threshold);
    }
})->with([
    'under the threshold when the reading was taken' => ['2026-09-17 09:58:02', null],
    'over the threshold when the reading was taken' => ['2026-09-17 09:57:58', 122.0],
]);

test('the failed jobs of the last hour are counted from the instant the reading was taken', function () {
    $failedAt = CarbonImmutable::parse('2026-09-17 09:00:02');

    ($this->poll)(function () use ($failedAt) {
        $this->travel(4)->seconds();

        return ($this->reading)(['failedJobs' => [new HorizonFailedJob(
            name: 'App\\Jobs\\SendInvoiceEmail',
            queue: 'emails',
            exception: 'RuntimeException',
            attempts: 1,
            failedAt: $failedAt,
        )]]);
    });

    expect(EnvironmentSnapshot::query()->sole()->failed_last_hour)->toBe(1);
});

test('deleting an environment locks its row before it touches the alerts', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    Alert::factory()->for($this->environment)->create();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->actingAs($owner)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $this->environment->slug]), [
            'name' => $this->environment->name,
        ])
        ->assertRedirect();

    expect(firstStatementIndex($statements, 'from "environments"', 'for update'))
        ->toBeLessThan(firstStatementIndex($statements, 'update "alerts"'));
});

test('deleting an application locks the rows of its environments, in id order, before it touches the alerts', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    Environment::factory()->for($this->application)->staging()->create();
    Alert::factory()->for($this->environment)->create();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->actingAs($owner)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertRedirect();

    $lock = firstStatementIndex($statements, 'from "environments"', 'for update');

    expect($lock)->toBeLessThan(firstStatementIndex($statements, 'update "alerts"'))
        ->and($statements[$lock])->toContain('order by "environments"."id" asc');
});

test('deleting an organization locks the rows of its environments before it touches the alerts', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    Alert::factory()->for($this->environment)->create();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $this->actingAs($owner)
        ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name])
        ->assertRedirect();

    expect(firstStatementIndex($statements, 'from "environments"', 'for update'))
        ->toBeLessThan(firstStatementIndex($statements, 'delete from "alerts"'));
});

/**
 * @param  list<string>  $statements
 */
function firstStatementIndex(array $statements, string ...$fragments): int
{
    foreach ($statements as $index => $sql) {
        if (array_all($fragments, fn (string $fragment) => str_contains($sql, $fragment))) {
            return $index;
        }
    }

    return PHP_INT_MAX;
}
