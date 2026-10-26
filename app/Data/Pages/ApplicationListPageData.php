<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class ApplicationListPageData extends Data
{
    public function __construct(
        /** @var array<int, ApplicationGroupData> */
        public array $groups,
        public int $environmentCount,
        public bool $canTransferConfiguration,
        /** @var array<int, string> */
        public array $sharedApplicationNames,
    ) {}
}
