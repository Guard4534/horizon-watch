<?php

namespace App\Actions\Monitoring;

use App\Jobs\PollEnvironmentJob;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DispatchDuePolls
{
    public function handle(): int
    {
        $now = CarbonImmutable::now()->startOfSecond();

        $due = DB::transaction(function () use ($now) {
            $environments = Environment::query()
                ->select(['id', 'poll_interval_seconds'])
                ->where('polling_enabled', true)
                ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', $now))
                ->orderByRaw('next_poll_at asc nulls first')
                ->orderBy('id')
                ->lock('for no key update skip locked')
                ->get();

            foreach ($environments as $environment) {
                Environment::query()
                    ->whereKey($environment->id)
                    ->toBase()
                    ->update(['next_poll_at' => $now->addSeconds($environment->poll_interval_seconds)]);
            }

            return $environments->modelKeys();
        });

        foreach ($due as $environmentId) {
            PollEnvironmentJob::dispatch($environmentId, $now->getTimestamp());
        }

        return count($due);
    }
}
