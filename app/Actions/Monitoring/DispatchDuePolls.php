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
     * The rows are locked with SKIP LOCKED, so two schedulers running at
     * once (a second host, an overlapping tick) split the due environments
     * instead of both reading them. The jobs are queued only once the new
     * next_poll_at is committed: a job pushed first could run and be
     * followed by a rollback that makes the environment due again.
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
                ->lock('for update skip locked')
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

        // Dispatched after the transaction has returned, not from inside
        // it with afterCommit alone: the unique lock is taken when a job is
        // dispatched, and one taken inside a transaction that then rolls
        // back would keep the environment unpolled for the lock's lifetime.
        // afterCommit still holds the push back if a caller has wrapped
        // this in a transaction of its own.
        foreach ($due as $environmentId) {
            PollEnvironmentJob::dispatch($environmentId)->afterCommit();
        }

        return count($due);
    }
}
