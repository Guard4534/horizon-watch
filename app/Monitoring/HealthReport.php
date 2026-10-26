<?php

namespace App\Monitoring;

final readonly class HealthReport
{
    /**
     * @param  array<string, int>  $queueWaitSeconds
     */
    public function __construct(
        public bool $database,
        public ?int $schedulerSecondsAgo,
        public array $queueWaitSeconds,
        public ?int $failedJobsLastHour,
    ) {}

    public function schedulerLate(): bool
    {
        return $this->schedulerSecondsAgo === null
            || $this->schedulerSecondsAgo > config()->integer('horizon-watch.health.scheduler_late_seconds');
    }

    public function oldestWaitSeconds(): ?int
    {
        return $this->queueWaitSeconds === [] ? null : max($this->queueWaitSeconds);
    }

    public function backlogLate(): bool
    {
        return ($this->oldestWaitSeconds() ?? 0) > config()->integer('horizon-watch.health.backlog_late_seconds');
    }

    public function healthy(): bool
    {
        return $this->database && ! $this->schedulerLate() && ! $this->backlogLate();
    }
}
