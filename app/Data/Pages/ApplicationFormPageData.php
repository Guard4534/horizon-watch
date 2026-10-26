<?php

namespace App\Data\Pages;

use App\Data\Applications\PollIntervalData;
use Spatie\LaravelData\Data;

class ApplicationFormPageData extends Data
{
    public function __construct(
        /**
         * @var array<int, array{value: string, label: string}>
         */
        public array $types,
        public PollIntervalData $pollInterval,
        public int $maxEnvironments,
    ) {}
}
