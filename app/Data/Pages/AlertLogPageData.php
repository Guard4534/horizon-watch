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
    ) {}
}
