<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class EnvironmentOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
