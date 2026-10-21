<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentType;
use App\Enums\MaintenanceScope;
use Spatie\LaravelData\Data;

class MaintenanceWindowData extends Data
{
    public function __construct(
        public int $id,
        public MaintenanceScope $scope,
        public ?string $applicationName,
        public ?string $environmentName,
        public ?EnvironmentType $environmentType,
        public string $startsAt,
        public string $endsAt,
        public bool $active,
        public bool $ended,
        public ?string $reason,
        public ?AlertActorData $createdBy,
        public bool $canDelete,
    ) {}
}
