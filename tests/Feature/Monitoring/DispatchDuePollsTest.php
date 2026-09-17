<?php

use App\Actions\Monitoring\DispatchDuePolls;
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

    // A fraction on purpose: the next poll is computed from whole seconds.
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00.700'));

    // next_poll_at is not mass assignable.
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

// Documents intent rather than guarding it: Laravel's binding format already
// drops the fraction, so this passes with or without the truncation in the
// action.
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

    // The faked queue never runs the job, so its unique lock is released
    // by hand, as the worker does when the job ends.
    (new UniqueLock(app(Cache::class)))->release(new PollEnvironmentJob($environment->id));

    // The next tick of the scheduler, a little later within its second.
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

    // The interval still moves: the missed reading is replaced by the next.
    expect($environment->fresh()->next_poll_at->toDateTimeString())->toBe('2026-09-17 10:00:30');
});

test('a claim that rolls back queues nothing', function () {
    $first = ($this->environment)();
    $second = ($this->environment)();

    // Fails the claim of the second environment, after the first one's
    // next_poll_at has been written: a job queued per row, before the
    // commit, would already be out.
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
