<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class AlertCountsData extends Data
{
    public function __construct(
        public int $open,
        public int $muted,
        public int $resolved,
    ) {}
}
