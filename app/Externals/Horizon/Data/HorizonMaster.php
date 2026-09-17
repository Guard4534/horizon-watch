<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonMaster
{
    /**
     * @param  list<HorizonSupervisor>  $supervisors
     */
    public function __construct(
        public string $name,
        public string $status,
        public array $supervisors,
    ) {}
}
