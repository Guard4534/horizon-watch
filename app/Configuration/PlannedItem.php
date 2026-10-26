<?php

namespace App\Configuration;

use App\Data\Alerts\AlertRuleInputData;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Configuration\ImportItemData;
use App\Models\Application;
use App\Models\Environment;

/**
 * One line of the preview plus what the importer needs to write it.
 */
final readonly class PlannedItem
{
    public function __construct(
        public ImportItemData $item,
        /** Application items and their environments: the lower-case application name; rules: the scope. */
        public string $key,
        public ?string $name = null,
        public ?Application $application = null,
        public ?Environment $environment = null,
        public ?EnvironmentFormData $environmentData = null,
        public ?AlertRuleInputData $rule = null,
    ) {}
}
