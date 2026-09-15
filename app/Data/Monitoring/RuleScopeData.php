<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use Spatie\LaravelData\Data;

class RuleScopeData extends Data
{
    public function __construct(
        public string $id,
        public ?EnvironmentColor $color,
        public int $environmentCount,
        public int $overrideCount,
    ) {}
}
