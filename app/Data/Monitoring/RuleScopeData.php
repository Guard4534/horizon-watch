<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentType;
use Spatie\LaravelData\Data;

class RuleScopeData extends Data
{
    public function __construct(
        public string $id,
        public ?EnvironmentType $type,
        public int $environmentCount,
        public int $overrideCount,
    ) {}
}
