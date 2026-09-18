<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class AlertPageData extends Data
{
    public function __construct(
        /** @var array<int, AlertData> */
        public array $alerts,
        public int $total,
        public int $perPage,
        public int $page,
    ) {}
}
