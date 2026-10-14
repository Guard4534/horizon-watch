<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\MaintenanceTargetData;
use App\Data\Monitoring\MaintenanceWindowData;
use App\Data\Monitoring\NotificationSettingsData;
use App\Data\Monitoring\RuleScopeData;
use Spatie\LaravelData\Data;

class AlertRulesPageData extends Data
{
    public function __construct(
        /** @var array<int, RuleScopeData> */
        public array $scopes,
        public string $scope,
        /** @var array<int, AlertRuleData> */
        public array $rules,
        /** @var array<int, AlertRuleData> */
        public array $organizationRules,
        public NotificationSummaryData $notificationSummary,
        public ?NotificationSettingsData $notifications,
        public bool $canManage,
        public ?string $newWebhookSecret,
        /** @var array<int, int> */
        public array $repeatChoices,
        public int $maxRecipients,
        public int $previousSecretHours,
        /** @var array<int, string> */
        public array $timezones,
        /** @var array<int, MaintenanceWindowData> */
        public array $maintenanceWindows,
        /** @var array<int, MaintenanceTargetData> */
        public array $maintenanceTargets,
        public int $maintenanceMaxDays,
    ) {}
}
