<?php

namespace App\Data\Pages;

use App\Data\Monitoring\SeriesGridData;
use App\Enums\SeriesRange;
use Spatie\LaravelData\Data;

class EnvironmentSeriesData extends Data
{
    public function __construct(
        public SeriesRange $range,
        /** @var array<int, int> */
        public array $throughput,
        /** @var array<int, int> */
        public array $maxWait,
        public SeriesGridData $grid,
    ) {}
}
