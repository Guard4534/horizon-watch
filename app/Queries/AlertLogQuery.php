<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Pages\AlertCountsData;
use App\Data\Pages\AlertLogPageData;
use App\Enums\AlertSeverity;
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

        $critical = array_values(array_filter($open, fn (AlertData $alert) => $alert->severity === AlertSeverity::Critical));

        return new AlertLogPageData(
            state: $state,
            counts: new AlertCountsData(count($open), count($muted), count($resolved)),
            alerts: match ($state) {
                AlertState::Open => $open,
                AlertState::Muted => $muted,
                AlertState::Resolved => $resolved,
            },
            preview: $critical[0] ?? $open[0] ?? null,
            environmentCount: count($this->monitoring->environments($team)),
        );
    }
}
