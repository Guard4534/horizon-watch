<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonQueueLoad
{
    public function __construct(
        public string $name,
        public int $length,
        public int $wait,
        public int $processes,
    ) {}
}
