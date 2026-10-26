<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentType;
use Spatie\LaravelData\Data;

class EnvironmentSummaryData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentType $type,
        public string $horizonUrl,
        public ?string $basicAuthUser,
        public int $pollIntervalSeconds,
        public bool $pollingEnabled,
    ) {}
}
