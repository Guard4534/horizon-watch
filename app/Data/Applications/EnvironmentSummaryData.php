<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use Spatie\LaravelData\Data;

class EnvironmentSummaryData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public ?string $basicAuthUser,
        public int $pollIntervalSeconds,
        public bool $pollingEnabled,
    ) {}
}
