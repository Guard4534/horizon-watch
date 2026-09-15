<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class ApplicationData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $host,
    ) {}
}
