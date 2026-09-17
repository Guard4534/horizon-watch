<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class EnvironmentData extends Data
{
    public function __construct(
        public string $id,
        public string $applicationId,
        public string $applicationName,
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public EnvironmentStatus $status,
        public int $pending,
        public int $maxWaitSeconds,
        public int $failedLast24Hours,
        public int $workers,
        public int $jobsPerMinute,
        public int $nodeCount,
        public ?string $basicAuthUser,
        public float $redisMemoryGb,
        public int $latencyMs,
        // Whether this environment is on the viewer's wall. False only on
        // the Applications pages, and only for a viewer whose *permission*
        // put it there while their visibility hides it (see the split note
        // in ConfiguredMonitoringRepository): those rows keep every
        // configuration control and lose the ones that would lead to the
        // operational view, which answers 404 for them. Everywhere else —
        // wall, alerts, environment detail — a row exists only if it is
        // watched, so it is true.
        public bool $watched,
    ) {}

    /**
     * The wall's ordering, and anywhere else that needs "worst first": lowest
     * severity wins, ties broken by the most pending jobs. The single owner
     * of this rule — every other call site (PHP or TypeScript) should use it
     * instead of re-implementing the tuple comparison.
     */
    public static function compareBySeverityThenPending(self $a, self $b): int
    {
        return [$a->status->severity(), $b->pending] <=> [$b->status->severity(), $a->pending];
    }
}
