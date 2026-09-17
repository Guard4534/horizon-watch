<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class MePageData extends Data
{
    public function __construct(
        public int $memberCount,
    ) {}
}
