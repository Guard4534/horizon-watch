<?php

namespace App\Queries;

use App\Data\Pages\AlertCountsData;
use App\Data\Pages\AlertLogPageData;
use App\Enums\AlertState;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class AlertLogQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, AlertState $state): AlertLogPageData
    {
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
            notifications: $this->monitoring->notificationSettings($team),
            environmentCount: count($this->monitoring->environments($team)),
        );
    }
}
