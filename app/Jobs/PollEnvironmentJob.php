<?php

namespace App\Jobs;

use App\Actions\Monitoring\PollEnvironment;
use App\Enums\ReadingCause;
use App\Models\Environment;
use App\Support\SafeReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollEnvironmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public readonly int $dispatchedAt;

    public function __construct(public readonly int $environmentId, ?int $dispatchedAt = null)
    {
        $this->dispatchedAt = $dispatchedAt ?? now()->getTimestamp();
        $this->timeout = config()->integer('horizon-watch.readings.poll_job_timeout_seconds');
        $this->uniqueFor = config()->integer('horizon-watch.readings.poll_job_unique_seconds');
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

        $waited = now()->getTimestamp() - $this->dispatchedAt;

        if ($waited > $environment->poll_interval_seconds) {
            Log::warning('A poll waited in the queue longer than its interval and was dropped.', [
                'environment' => $environment->id,
                'waited_seconds' => $waited,
            ]);

            $this->keepDue();

            return;
        }

        if ($this->polledSinceDispatch($environment)) {
            return;
        }

        $poll->handle($environment);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $environment = Environment::query()
                ->whereKey($this->environmentId)
                ->where('polling_enabled', true)
                ->first();

            if ($environment !== null && $this->isCurrentPollOf($environment)) {
                app(PollEnvironment::class)->recordFailure(
                    $environment,
                    $exception instanceof MaxAttemptsExceededException ? ReadingCause::Timeout : ReadingCause::PollFailed,
                );
            }
        } catch (Throwable $failure) {
            SafeReport::of('Recording a failed poll', $failure);
        }
    }

    private function isCurrentPollOf(Environment $environment): bool
    {
        return ! $this->polledSinceDispatch($environment)
            && now()->getTimestamp() - $this->dispatchedAt <= $environment->poll_interval_seconds + $this->timeout;
    }

    private function polledSinceDispatch(Environment $environment): bool
    {
        return $environment->last_polled_at !== null && $environment->last_polled_at->getTimestamp() >= $this->dispatchedAt;
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
