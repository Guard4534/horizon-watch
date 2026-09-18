<?php

namespace App\Queries;

use App\Data\Pages\MePageData;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;

class MeQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer): MePageData
    {
        $settings = $this->monitoring->notificationSettings($team);

        return new MePageData(
            memberCount: $team->members()->count(),
            alertEmails: $viewer->alert_emails,
            quietFrom: $settings->quietFrom,
            quietTo: $settings->quietTo,
        );
    }
}
