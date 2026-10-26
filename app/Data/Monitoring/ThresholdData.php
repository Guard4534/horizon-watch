<?php

namespace App\Data\Monitoring;

use App\Enums\AlertSeverity;
use Spatie\LaravelData\Data;

class ThresholdData extends Data
{
    public function __construct(
        public float $value,
        public AlertSeverity $severity,
    ) {}
}
