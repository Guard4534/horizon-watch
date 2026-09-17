<?php

use App\Actions\Monitoring\DispatchDuePolls;
use App\Actions\Monitoring\PollEnvironment;
use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Jobs\PollEnvironmentJob;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00.700'));

    $this->environment = fn (array $attributes = [], ?string $nextPollAt = null): Environment => tap(
        Environment::factory()->create($attributes),
        fn (Environment $environment) => $environment->forceFill(['next_poll_at' => $nextPollAt])->save(),
    );

    $this->dispatch = fn (): int => app(DispatchDuePolls::class)->handle();
});

test('only enabled environments that are due are queued', function () {
    $overdue = ($this->environment)([], '2026-09-17 09:59:00');
    $dueNow = ($this->environment)([], '2026-09-17 10:00:00');
    $neverPolled = ($this->environment)();
    $notYet = ($this->environment)([], '2026-09-17 10:00:01');
    $paused = ($this->environment)(['polling_enabled' => false], '2026-09-17 09:00:00');
    $pausedNeverPolled = ($this->environment)(['polling_enabled' => false]);

    expect(($this->dispatch)())->toBe(3);

    Queue::assertPushed(PollEnvironmentJob::class, 3);

    $queued = Queue::pushed(PollEnvironmentJob::class)->map(fn (PollEnvironmentJob $job) => $job->environmentId)->sort()->values()->all();

    expect($queued)->toBe([$overdue->id, $dueNow->id, $neverPolled->id])
        ->and($notYet->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:01')
        ->and($paused->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 09:00:00')
        ->and($pausedNeverPolled->fresh()->next_poll_at)->toBeNull();
});

test('next_poll_at moves one interval ahead of the current whole second', function () {
    $fast = ($this->environment)(['poll_interval_seconds' => 15], '2026-09-17 09:59:00');
    $slow = ($this->environment)(['poll_interval_seconds' => 300]);

    ($this->dispatch)();

    expect($fast->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($slow->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:05:00');
});

test('a second run right after the first queues nothing', function () {
    ($this->environment)();
    ($this->environment)([], '2026-09-17 09:59:00');

    expect(($this->dispatch)())->toBe(2)
        ->and(($this->dispatch)())->toBe(0);

    Queue::assertPushed(PollEnvironmentJob::class, 2);
});

test('the next tick, one interval later, queues the environment again once its job has run', function () {
    $environment = ($this->environment)(['poll_interval_seconds' => 15]);

    ($this->dispatch)();

    (new UniqueLock(app(Cache::class)))->release(new PollEnvironmentJob($environment->id));

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15.100'));

    expect(($this->dispatch)())->toBe(1)
        ->and($environment->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:30');

    Queue::assertPushed(PollEnvironmentJob::class, 2);
});

test('an environment due again while its previous job still waits is not queued twice', function () {
    $environment = ($this->environment)(['poll_interval_seconds' => 15]);

    ($this->dispatch)();

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15.100'));

    ($this->dispatch)();

    Queue::assertPushed(PollEnvironmentJob::class, 1);

    expect($environment->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:30');
});

test('a claim that rolls back queues nothing', function () {
    $first = ($this->environment)();
    $second = ($this->environment)();

    DB::listen(function (QueryExecuted $query) use ($second) {
        if (str_starts_with($query->sql, 'update "environments"') && in_array($second->id, $query->bindings, true)) {
            throw new RuntimeException('Claim failed.');
        }
    });

    expect(fn () => ($this->dispatch)())->toThrow(RuntimeException::class, 'Claim failed.');

    Queue::assertNothingPushed();

    expect($first->fresh()->next_poll_at)->toBeNull()
        ->and($second->fresh()->next_poll_at)->toBeNull();
});

test('the jobs carry the dispatch time and updated_at is left alone', function () {
    $environment = ($this->environment)();
    $updatedAt = $environment->fresh()->updated_at->toDateTimeString();

    $this->travelTo(CarbonImmutable::parse('2026-09-17 11:00:00.700'));
    ($this->dispatch)();

    Queue::assertPushed(PollEnvironmentJob::class, fn (PollEnvironmentJob $job) => $job->environmentId === $environment->id
        && $job->dispatchedAt === CarbonImmutable::parse('2026-09-17 11:00:00')->getTimestamp());

    expect($environment->fresh()->updated_at->toDateTimeString())->toBe($updatedAt);
});

test('with nothing configured nothing is queued', function () {
    expect(($this->dispatch)())->toBe(0);

    Queue::assertNothingPushed();
});

test('due environments are queued never read first, then oldest due first, id breaking ties', function () {
    $lateTie = ($this->environment)([], '2026-09-17 09:59:30');
    $oldest = ($this->environment)([], '2026-09-17 09:58:00');
    $neverPolled = ($this->environment)();
    $earlyTie = ($this->environment)([], '2026-09-17 09:59:30');

    ($this->dispatch)();

    $queued = Queue::pushed(PollEnvironmentJob::class)->map(fn (PollEnvironmentJob $job) => $job->environmentId)->values()->all();

    expect($queued)->toBe([$neverPolled->id, $oldest->id, $lateTie->id, $earlyTie->id]);
});

test('the claim query filters and sorts through the polling index', function () {
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"next_poll_at"')) {
            $queries[] = $query;
        }
    });

    ($this->dispatch)();

    expect($queries)->toHaveCount(1);

    DB::statement('set enable_seqscan = off');

    $plan = collect(DB::select('explain '.$queries[0]->sql, $queries[0]->bindings))->pluck('QUERY PLAN')->implode("\n");

    DB::statement('reset enable_seqscan');

    expect($plan)->toContain('environments_polling_enabled_next_poll_at_index');
});

test('an environment whose job was dropped for its age is queued first on the next tick', function () {
    $environments = collect(range(1, 3))->map(fn () => ($this->environment)(['poll_interval_seconds' => 15]));

    ($this->dispatch)();

    $release = fn () => $environments->each(fn (Environment $environment) => (new UniqueLock(app(Cache::class)))->release(new PollEnvironmentJob($environment->id)));

    $jobs = Queue::pushed(PollEnvironmentJob::class)->values();
    $reader = Mockery::mock(HorizonReader::class);
    $reader->shouldReceive('read')->once()->andThrow(new HorizonReadFailed(ReadingError::Unreachable));
    $this->app->instance(HorizonReader::class, $reader);

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15.200'));
    $jobs[0]->handle(app(PollEnvironment::class));

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:16.200'));
    $jobs[1]->handle(app(PollEnvironment::class));
    $jobs[2]->handle(app(PollEnvironment::class));
    $release();

    expect($environments[0]->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($environments[1]->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($environments[2]->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:00');

    expect(($this->dispatch)())->toBe(3);

    $queued = Queue::pushed(PollEnvironmentJob::class)->skip(3)->map(fn (PollEnvironmentJob $job) => $job->environmentId)->values()->all();

    expect($queued)->toBe([$environments[1]->id, $environments[2]->id, $environments[0]->id]);
});

test('a dropped job never moves next_poll_at later', function () {
    $environment = ($this->environment)(['poll_interval_seconds' => 15], '2026-09-17 09:59:40');

    (new PollEnvironmentJob($environment->id, CarbonImmutable::parse('2026-09-17 09:59:44')->getTimestamp()))
        ->handle(app(PollEnvironment::class));

    expect($environment->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 09:59:40');
});
