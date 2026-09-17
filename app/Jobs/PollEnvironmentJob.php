<?php

namespace App\Jobs;

use App\Actions\Monitoring\PollEnvironment;
use App\Models\Environment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries the environment id and the dispatch time, nothing else: the
 * target, with its decrypted password, is built inside the worker, so the
 * payload in the jobs and failed_jobs tables never holds a credential. Not
 * SerializesModels either, which would fail the job on a deleted
 * environment instead of letting it end quietly.
 */
class PollEnvironmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A lost reading is replaced by the next one; a retry would only arrive
     * late and out of order.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /**
     * The lock is released when the job ends; this bounds it when the job
     * never runs (a stopped worker), so polling resumes a minute later.
     * Past it a backlog can hold two jobs for one environment: the checks
     * in handle() make the extra one end without reading.
     */
    public int $uniqueFor = 60;

    /**
     * Unix seconds.
     */
    public readonly int $dispatchedAt;

    public function __construct(public readonly int $environmentId, ?int $dispatchedAt = null)
    {
        $this->dispatchedAt = $dispatchedAt ?? now()->getTimestamp();
    }

    public function uniqueId(): string
    {
        return (string) $this->environmentId;
    }

    public function handle(PollEnvironment $poll): void
    {
        // Paused or deleted between the dispatch and now.
        $environment = Environment::query()
            ->whereKey($this->environmentId)
            ->where('polling_enabled', true)
            ->first();

        if ($environment === null) {
            return;
        }

        // A late job drops itself. One serial worker behind slow
        // environments would otherwise fall further behind every tick, and
        // every environment would go stale exactly when it matters. Older
        // than an interval: the scheduler has queued its successor, or is
        // about to. A reading at least as recent as the dispatch: a
        // duplicate already did the work.
        if (now()->getTimestamp() - $this->dispatchedAt > $environment->poll_interval_seconds) {
            return;
        }

        if ($environment->last_polled_at !== null && $environment->last_polled_at->getTimestamp() >= $this->dispatchedAt) {
            return;
        }

        $poll->handle($environment);
    }
}
