<?php

namespace App\Data\Pages;

use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Enums\EnvironmentStatus;
use Spatie\LaravelData\Data;

class ApplicationGroupData extends Data
{
    public function __construct(
        public ApplicationData $application,
        /** @var array<int, EnvironmentData> */
        public array $environments,
        public int $attentionCount,
        public ?EnvironmentStatus $worstStatus,
    ) {}
}
