<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class SetupPageData extends Data
{
    public function __construct(
        public bool $requiresToken,
    ) {}
}
