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
        /** @var array<int, EnvironmentData> */
        public array $environments,
        /** @var array<int, AlertData> */
        public array $recentAlerts,
        public ?EnvironmentStatus $worstStatus,
        /** @var array<string, float> */
        public array $thresholds,
    ) {}
}
