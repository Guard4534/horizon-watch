<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use App\Enums\MaintenanceScope;
use Spatie\LaravelData\Data;

class MaintenanceTargetData extends Data
{
    public function __construct(
        public MaintenanceScope $scope,
        public ?string $id,
        public ?string $applicationName,
        public ?string $environmentName,
        public ?EnvironmentColor $color,
    ) {}
}
