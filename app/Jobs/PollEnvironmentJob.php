<?php

namespace App\Jobs;

use App\Actions\Monitoring\PollEnvironment;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollEnvironmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 60;

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
        $environment = Environment::query()
            ->whereKey($this->environmentId)
            ->where('polling_enabled', true)
            ->first();

        if ($environment === null) {
            return;
        }

        if (now()->getTimestamp() - $this->dispatchedAt > $environment->poll_interval_seconds) {
            $this->keepDue();

            return;
        }

        if ($environment->last_polled_at !== null && $environment->last_polled_at->getTimestamp() >= $this->dispatchedAt) {
            return;
        }

        $poll->handle($environment);
    }

    private function keepDue(): void
    {
        $dispatchedAt = CarbonImmutable::createFromTimestamp($this->dispatchedAt);

        Environment::query()
            ->whereKey($this->environmentId)
            ->where('next_poll_at', '>', $dispatchedAt)
            ->toBase()
            ->update(['next_poll_at' => $dispatchedAt]);
    }
}
