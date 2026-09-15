<?php

namespace App\Data\Pages;

use App\Data\Monitoring\EnvironmentData;
use Spatie\LaravelData\Data;

class EnvironmentCardData extends Data
{
    public function __construct(
        public EnvironmentData $environment,
        /** @var array<int, int> */
        public array $sparkline,
    ) {}
}
