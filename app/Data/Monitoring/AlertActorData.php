<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class AlertActorData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
