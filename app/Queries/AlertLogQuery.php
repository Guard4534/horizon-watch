<?php

namespace App\Queries;

use App\Data\Pages\AlertCountsData;
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

    public function handle(Team $team, User $viewer, AlertState $state): AlertLogPageData
    {
        $settings = $this->monitoring->notificationSettings($team);

        $open = $this->monitoring->alerts($team, AlertState::Open);
        $muted = $this->monitoring->alerts($team, AlertState::Muted);
        $resolved = $this->monitoring->alerts($team, AlertState::Resolved);

        return new AlertLogPageData(
            state: $state,
            counts: new AlertCountsData(count($open), count($muted), count($resolved)),
            alerts: match ($state) {
                AlertState::Open => $open,
                AlertState::Muted => $muted,
                AlertState::Resolved => $resolved,
            },
            notificationSummary: NotificationSummaryData::of($settings),
            notifications: Gate::forUser($viewer)->allows('manageAlertRules', $team) ? $settings : null,
            environmentCount: count($this->monitoring->environments($team)),
        );
    }
}
