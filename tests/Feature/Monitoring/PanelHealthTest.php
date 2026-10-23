<?php

use App\Actions\Monitoring\PingHeartbeat;
use App\Externals\Http\Dns\Resolver;
use App\Models\User;
use App\Monitoring\PanelHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Horizon\FakeResolver;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'));
    $this->health = app(PanelHealth::class);
    $this->tickScheduler = fn () => collect(PanelHealth::SCHEDULED_TASKS)->each(fn (string $task) => $this->health->recordSchedulerTick($task));

    $this->queueJob = fn (string $queue, int $availableAgo, ?int $reservedAgo = null) => DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAgo === null ? null : now()->getTimestamp() - $reservedAgo,
        'available_at' => now()->getTimestamp() - $availableAgo,
        'created_at' => now()->getTimestamp() - max(0, $availableAgo),
    ]);

    $this->failJob = fn (string $failedAt) => DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'polls',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => $failedAt,
    ]);

    $this->defaultConnection = config('database.default');

    $this->breakDatabase = function () {
        config([
            'app.debug' => false,
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/horizon-watch.sqlite', 'prefix' => ''],
            'database.default' => 'broken',
        ]);
    };
});

afterEach(function () {
    config(['database.default' => $this->defaultConnection]);
});

function scheduledHealthEvent(string $name): ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => $event->description === $name)
        ?? throw new RuntimeException("No scheduled event {$name}.");
}

test('the health endpoint answers without signing in and reports every check', function () {
    config(['queue.default' => 'database', 'horizon-watch.version' => '1.0.0']);
    ($this->tickScheduler)();
    $this->travel(30)->seconds();
    ($this->queueJob)('polls', 4);
    ($this->queueJob)('notifications', 20);
    ($this->failJob)('2026-10-13 11:30:00');
    ($this->failJob)('2026-10-13 10:30:00');

    $this->getJson('/health')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'status' => 'ok',
            'version' => '1.0.0',
            'checks' => [
                'database' => ['ok' => true],
                'scheduler' => ['ok' => true, 'last_run_seconds_ago' => 30],
                'queues' => [
                    'ok' => true,
                    'oldest_wait_seconds' => ['notifications' => 20, 'polls' => 4],
                    'failed_jobs_last_hour' => 1,
                ],
            ],
        ]);
});

test('the health endpoint fails while the scheduler has not run lately', function () {
    $this->getJson('/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.scheduler', ['ok' => false, 'last_run_seconds_ago' => null]);

    ($this->tickScheduler)();
    $this->travel(120)->seconds();

    $this->getJson('/health')->assertOk();

    $this->travel(1)->seconds();

    $this->getJson('/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('checks.scheduler', ['ok' => false, 'last_run_seconds_ago' => 121]);
});

test('the health endpoint fails when a job waits too long for a worker', function () {
    config(['queue.default' => 'database']);
    ($this->tickScheduler)();
    ($this->queueJob)('polls', 900, reservedAgo: 10);
    ($this->queueJob)('notifications', -60);
    ($this->queueJob)('notifications', 300);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('checks.queues.oldest_wait_seconds', ['notifications' => 300]);

    ($this->queueJob)('polls', 400, reservedAgo: 200);

    $this->getJson('/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.scheduler.ok', true)
        ->assertJsonPath('checks.queues.ok', false)
        ->assertJsonPath('checks.queues.oldest_wait_seconds', ['notifications' => 300, 'polls' => 400]);
});

test('the health endpoint fails when the database is unreachable, and so does /up', function () {
    $this->get('/up')->assertOk();

    ($this->breakDatabase)();

    $this->getJson('/health')
        ->assertServiceUnavailable()
        ->assertJsonPath('status', 'failing')
        ->assertJsonPath('checks.database.ok', false)
        ->assertJsonPath('checks.queues.ok', false);

    $this->get('/up')->assertInternalServerError();
});

test('the scheduler is on time only while it both dispatches the due polls and repeats the alerts', function () {
    Queue::fake();

    $this->artisan('schedule:test', ['--name' => 'dispatch-due-polls'])->assertSuccessful();

    expect($this->health->schedulerSecondsAgo())->toBeNull();

    $this->travel(5)->seconds();
    $this->artisan('schedule:test', ['--name' => 'alert-repeats'])->assertSuccessful();
    $this->travel(5)->seconds();

    expect($this->health->schedulerSecondsAgo())->toBe(10);

    $this->travel(100)->seconds();
    $this->artisan('schedule:test', ['--name' => 'dispatch-due-polls'])->assertSuccessful();
    $this->travel(20)->seconds();

    expect($this->health->schedulerSecondsAgo())->toBe(125);
    $this->getJson('/health')->assertServiceUnavailable()->assertJsonPath('checks.scheduler.last_run_seconds_ago', 125);
});

test('a worker records that it is alive at most every few seconds', function () {
    Event::dispatch(new Looping('database', 'polls,default'));

    expect($this->health->workerSecondsAgo())->toBe(0);

    $this->travel(10)->seconds();
    Event::dispatch(new Looping('database', 'polls,default'));

    expect($this->health->workerSecondsAgo())->toBe(10);

    $this->travel(5)->seconds();
    Event::dispatch(new Looping('database', 'polls,default'));

    expect($this->health->workerSecondsAgo())->toBe(0);
});

test('the health command checks the whole panel, the scheduler or this worker', function () {
    $this->artisan('horizon-watch:health')->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'scheduler'])->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'worker'])->assertFailed();

    ($this->tickScheduler)();
    $this->health->recordWorkerBeat();

    $this->artisan('horizon-watch:health')->assertSuccessful();
    $this->artisan('horizon-watch:health', ['--role' => 'scheduler'])->assertSuccessful();
    $this->artisan('horizon-watch:health', ['--role' => 'worker'])->assertSuccessful();

    $this->travel(121)->seconds();

    $this->artisan('horizon-watch:health', ['--role' => 'scheduler'])->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'worker'])->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'web'])->assertExitCode(2);
});

test('a backlog fails the panel check but not the scheduler of its container', function () {
    config(['queue.default' => 'database']);
    ($this->tickScheduler)();
    ($this->queueJob)('polls', 301);

    $this->artisan('horizon-watch:health')->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'scheduler'])->assertSuccessful();
});

test('the health command fails when the database is unreachable', function () {
    ($this->breakDatabase)();

    $this->artisan('horizon-watch:health', ['--role' => 'scheduler'])->assertFailed();
    $this->artisan('horizon-watch:health', ['--role' => 'worker'])->assertFailed();
});

test('signed-in pages share the health of the panel, guest pages do not', function () {
    $user = User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('panelHealth', null));

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelHealth.healthy', false)
            ->where('panelHealth.schedulerLate', true)
            ->where('panelHealth.schedulerSecondsAgo', null)
            ->where('panelHealth.backlogLate', false));

    ($this->tickScheduler)();
    $this->travel(40)->seconds();

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelHealth.healthy', true)
            ->where('panelHealth.schedulerSecondsAgo', 40));
});

test('the heartbeat is pinged every minute, only when a heartbeat address is configured', function () {
    $event = scheduledHealthEvent('heartbeat');

    expect($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('horizon-watch:heartbeat')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeFalse();

    config(['horizon-watch.health.heartbeat_url' => 'https://hc.example.com/ping/abc']);

    expect($event->filtersPass(app()))->toBeTrue();
});

test('the heartbeat is sent only while the panel is healthy, through the outbound address rules', function () {
    config(['horizon-watch.version' => '1.0.0']);
    $this->app->instance(Resolver::class, new FakeResolver(['hc.example.com' => ['203.0.113.20']]));
    config(['horizon-watch.health.heartbeat_url' => 'https://hc.example.com/ping/abc?status=up']);
    Http::preventStrayRequests();
    Http::fake(['hc.example.com/*' => Http::response('OK')]);

    expect(app(PingHeartbeat::class)->handle())->toBeFalse();
    Http::assertNothingSent();

    ($this->tickScheduler)();

    expect(app(PingHeartbeat::class)->handle())->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://hc.example.com/ping/abc?status=up'
        && $request->header('User-Agent') === ['HorizonWatch/1.0.0']);
});

test('a heartbeat refused by the address rules or by the receiver is not counted as sent', function (string $url, Closure $response) {
    $this->app->instance(Resolver::class, new FakeResolver(['hc.example.com' => ['203.0.113.20'], 'metadata.example.com' => ['169.254.169.254']]));
    config(['horizon-watch.health.heartbeat_url' => $url]);
    ($this->tickScheduler)();
    Http::preventStrayRequests();
    Http::fake(['*' => $response()]);

    expect(app(PingHeartbeat::class)->handle())->toBeFalse();
})->with([
    'link-local address' => ['https://metadata.example.com/ping', fn () => Http::response('OK')],
    'unknown host' => ['https://nowhere.example.com/ping', fn () => Http::response('OK')],
    'redirect' => ['https://hc.example.com/ping', fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example.com'])],
    'server error' => ['https://hc.example.com/ping', fn () => Http::response('', 500)],
]);

test('the heartbeat command reports whether the heartbeat was sent', function () {
    $this->artisan('horizon-watch:heartbeat')->assertSuccessful()->expectsOutputToContain('No heartbeat address is configured.');

    $this->app->instance(Resolver::class, new FakeResolver(['hc.example.com' => ['203.0.113.20']]));
    config(['horizon-watch.health.heartbeat_url' => 'https://hc.example.com/ping/abc']);
    Http::preventStrayRequests();
    Http::fake(['hc.example.com/*' => Http::response('OK')]);

    $this->artisan('horizon-watch:heartbeat')->assertFailed();
    Http::assertNothingSent();

    ($this->tickScheduler)();

    $this->artisan('horizon-watch:heartbeat')->assertSuccessful();
    Http::assertSentCount(1);
});

test('signed-in pages do not count the failed jobs for the banner', function () {
    $user = User::factory()->create();
    ($this->tickScheduler)();

    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('panelHealth.healthy', true));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'failed_jobs')))->toBeEmpty();
});
