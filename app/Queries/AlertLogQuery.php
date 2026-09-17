<?php

namespace App\Queries;

use App\Data\Pages\AlertLogPageData;
use App\Data\Pages\NotificationSummaryData;
use App\Enums\AlertState;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Support\Facades\Gate;

class AlertLogQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer, AlertState $state, ?string $application = null, int $page = 1): AlertLogPageData
    {
        $settings = $this->monitoring->notificationSettings($team);
        $alerts = $this->monitoring->alerts($team, $state, $application, $page);

        return new AlertLogPageData(
            state: $state,
            counts: $this->monitoring->alertCounts($team),
            alerts: $alerts->alerts,
            page: $alerts->page,
            total: $alerts->total,
            perPage: $alerts->perPage,
            application: $application,
            applications: $this->monitoring->applications($team),
            notificationSummary: NotificationSummaryData::of($settings),
            notifications: Gate::forUser($viewer)->allows('manageAlertRules', $team) ? $settings : null,
            environmentCount: count($this->monitoring->environments($team)),
        );
    }
}
