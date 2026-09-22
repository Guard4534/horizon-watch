<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class SeriesGridData extends Data
{
    public function __construct(
        public string $startsAt,
        public int $stepSeconds,
    ) {}
}
