<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Enums\AlertState;
use Spatie\LaravelData\Data;

class AlertLogPageData extends Data
{
    public function __construct(
        public AlertState $state,
        public AlertCountsData $counts,
        /** @var array<int, AlertData> */
        public array $alerts,
        public ?AlertData $preview,
        // Zero means the organization has no visible environment at all, so
        // there is nothing for an alert to be about yet: the page shows the
        // empty state instead of three empty tabs.
        public int $environmentCount,
    ) {}
}
