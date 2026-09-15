<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\ApplicationData;
use Spatie\LaravelData\Data;

class ApplicationDetailPageData extends Data
{
    public function __construct(
        public ApplicationData $application,
        /** @var array<int, EnvironmentCardData> */
        public array $cards,
        /** @var array<int, AlertData> */
        public array $recentAlerts,
    ) {}
}
