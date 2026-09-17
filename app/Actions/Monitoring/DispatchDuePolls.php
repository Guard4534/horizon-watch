<?php

namespace App\Actions\Monitoring;

use App\Jobs\PollEnvironmentJob;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DispatchDuePolls
{
    /**
     * Queue a reading for every enabled environment whose next poll is due,
     * and move its next poll one interval ahead. Returns how many were
     * queued.
     *
     * The jobs are queued only once the new next_poll_at is committed: a
     * job pushed first could run and be followed by a rollback that makes
     * the environment due again.
     *
     * Must not be called inside a transaction. On this stack the unique job
     * lock is a row in the database cache table, on the same connection:
     * when the lock is already held its insert fails, and on PostgreSQL a
     * failed statement aborts the whole surrounding transaction.
     */
    public function handle(): int
    {
        // Whole seconds, on purpose: the column is timestamp(0). A
        // fractional "now + 15s" rounded up would land after the next tick
        // and an environment polled every 15 seconds would be read every 30.
        // Laravel's binding format happens to drop the fraction today; this
        // does not rely on it.
        $now = CarbonImmutable::now()->startOfSecond();

        $due = DB::transaction(function () use ($now) {
            $environments = Environment::query()
                ->select(['id', 'poll_interval_seconds'])
                ->where('polling_enabled', true)
                ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', $now))
                ->orderBy('id')
                // NO KEY UPDATE, not UPDATE: a poll storing its reading
                // holds FOR KEY SHARE on the row through the snapshot's
                // foreign key, which FOR UPDATE conflicts with, so SKIP
                // LOCKED would pass over that environment for a whole
                // interval. SKIP LOCKED itself is not needed for
                // correctness: under READ COMMITTED a second scheduler would
                // wait, re-check the condition and find the row no longer
                // due. It only saves that wait.
                ->lock('for no key update skip locked')
                ->get();

            foreach ($environments as $environment) {
                // The base query leaves updated_at alone: it records
                // configuration changes, not polls.
                Environment::query()
                    ->whereKey($environment->id)
                    ->toBase()
                    ->update(['next_poll_at' => $now->addSeconds($environment->poll_interval_seconds)]);
            }

            return $environments->modelKeys();
        });

        // Dispatched after the transaction has returned, for the two
        // reasons in the docblock: the job cannot run before next_poll_at
        // is committed, and a refused unique lock cannot abort the claim.
        foreach ($due as $environmentId) {
            PollEnvironmentJob::dispatch($environmentId, $now->getTimestamp());
        }

        return count($due);
    }
}
