<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
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
        // Average pending of each five-minute bucket of the last hour,
        // oldest first, 0 where nothing was read.
        /** @var array<int, int> */
        public array $trend,
        // Last three buckets with data against the three before them, in
        // percent; null when there are not six or the base is 0.
        public ?int $trendPercent,
        public int $maxWaitSeconds,
        // Counted over $failedWindowMinutes, which Horizon states (often a
        // week): the name predates the window.
        public int $failedLast24Hours,
        public int $failedWindowMinutes,
        public int $workers,
        public int $jobsPerMinute,
        public int $nodeCount,
        public ?string $basicAuthUser,
        // Null when the latest reading failed: nothing answered to time.
        public ?int $latencyMs,
        // Whether this environment is on the viewer's wall. False only on
        // the Applications pages, and only for a viewer whose *permission*
        // put it there while their visibility hides it (see the split note
        // in ConfiguredMonitoringRepository): those rows keep every
        // configuration control and lose the ones that would lead to the
        // operational view, which answers 404 for them. Everywhere else —
        // wall, alerts, environment detail — a row exists only if it is
        // watched, so it is true.
        public bool $watched,
        // ISO-8601, null until the first reading lands.
        public ?string $lastReadingAt,
        // No reading for too many poll intervals. Never true while the
        // collection is paused: the pause already explains the silence.
        public bool $stale,
        // The collection switch of the environment, not Horizon's own
        // "paused" status.
        public bool $pollingEnabled,
        public ?ReadingError $readingError,
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
