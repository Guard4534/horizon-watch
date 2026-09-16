<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

/**
 * An environment as an option in a picker: the numeric id the payload
 * carries and the "application / environment" label a human reads.
 */
class EnvironmentOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
