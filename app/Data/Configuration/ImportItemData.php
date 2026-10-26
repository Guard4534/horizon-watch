<?php

namespace App\Data\Configuration;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentType;
use App\Enums\ImportItemKind;
use App\Enums\ImportItemStatus;
use Spatie\LaravelData\Data;

class ImportItemData extends Data
{
    public function __construct(
        public ImportItemKind $kind,
        /** Application name, environment name, or "<scope> / <metric>" for a rule, as the file writes it. */
        public string $label,
        public ?string $application,
        public ?string $scope,
        public ?AlertRuleMetric $metric,
        public ?EnvironmentType $type,
        public ImportItemStatus $status,
        /** @var array<int, string> */
        public array $changes,
        /** @var array<int, string> */
        public array $errors,
        public bool $passwordNeeded,
    ) {}
}
