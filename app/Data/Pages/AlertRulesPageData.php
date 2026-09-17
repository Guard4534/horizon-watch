<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertRuleData;
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
        public NotificationSummaryData $notificationSummary,
        // Recipients and webhook URL belong to ManageAlertRules: null for
        // everyone else.
        public ?NotificationSettingsData $notifications,
    ) {}
}
