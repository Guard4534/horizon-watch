<?php

namespace App\Data\Pages;

use App\Enums\EnvironmentType;
use Spatie\LaravelData\Data;

class EnvironmentOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public EnvironmentType $type,
    ) {}
}
