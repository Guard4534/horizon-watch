<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\NotificationSettingsData;
use App\Enums\AlertState;
use Spatie\LaravelData\Data;

class AlertLogPageData extends Data
{
    public function __construct(
        public AlertState $state,
        public AlertCountsData $counts,
        /** @var array<int, AlertData> */
        public array $alerts,
        public int $page,
        public int $total,
        public int $perPage,
        public ?string $application,
        /** @var array<int, ApplicationData> */
        public array $applications,
        public NotificationSummaryData $notificationSummary,
        public ?NotificationSettingsData $notifications,
        public int $environmentCount,
    ) {}
}
