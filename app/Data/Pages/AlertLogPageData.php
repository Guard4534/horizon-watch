<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
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
        public NotificationSummaryData $notificationSummary,
        public ?NotificationSettingsData $notifications,
        public int $environmentCount,
    ) {}
}
