<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\ApplicationData;
use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class ApplicationDetailPageData extends Data
{
    public function __construct(
        public ApplicationData $application,
        /** @var array<int, EnvironmentCardData> */
        public array $cards,
        /** @var array<int, AlertData> */
        public array $recentAlerts,
        // The status of this application's worst environment (by
        // EnvironmentData::compareBySeverityThenPending()), or null when it
        // has none. Computed here so the front end stops re-deriving it.
        public ?EnvironmentStatus $worstStatus,
    ) {}
}
