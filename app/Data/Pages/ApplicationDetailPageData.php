<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class ApplicationDetailPageData extends Data
{
    public function __construct(
        public ApplicationData $application,
        // The configuration view: unwatched rows included, without readings.
        // Each card draws EnvironmentData::$trend, like the wall's tiles.
        /** @var array<int, EnvironmentData> */
        public array $environments,
        /** @var array<int, AlertData> */
        public array $recentAlerts,
        // The status of this application's worst environment (by
        // EnvironmentData::compareBySeverityThenPending()), or null when it
        // has none. Computed here so the front end stops re-deriving it.
        public ?EnvironmentStatus $worstStatus,
        // The organization defaults keyed by metric, the values the
        // evaluator uses: the cards and the comparison colour against them
        // (failed counts by their hourly rate, over each row's own window).
        /** @var array<string, float> */
        public array $thresholds,
    ) {}
}
