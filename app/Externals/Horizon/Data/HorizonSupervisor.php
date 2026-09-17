<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonSupervisor
{
    /**
     * @param  array<string, int>  $processes  keyed by "connection:queue"
     */
    public function __construct(
        public string $name,
        public string $status,
        public array $processes,
    ) {}
}
