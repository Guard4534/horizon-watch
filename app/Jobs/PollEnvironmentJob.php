<?php

namespace App\Jobs;

use App\Actions\Monitoring\PollEnvironment;
use App\Models\Environment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries the environment id and nothing else: the target, with its
 * decrypted password, is built inside the worker, so the payload in the
 * jobs and failed_jobs tables never holds a credential. Not SerializesModels
 * either, which would fail the job on a deleted environment instead of
 * letting it end quietly.
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
     */
    public int $uniqueFor = 60;

    public function __construct(public readonly int $environmentId) {}

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

        $poll->handle($environment);
    }
}
