<?php

namespace App\Queries;

use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationGroupData;
use App\Data\Pages\ApplicationListPageData;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class ApplicationListQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    /**
     * The Applications view lists what the viewer may configure, not what
     * they watch: a member who may manage applications sees every
     * environment of the organization here, even the ones their own
     * visibility hides from the wall (phase 2 spec, "la visibilità non è un
     * permesso"). For everybody else the two lists are the same.
     */
    public function handle(Team $team): ApplicationListPageData
    {
        $environments = $this->monitoring->configurableEnvironments($team);

        $groups = array_map(function (ApplicationData $application) use ($environments) {
            $own = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->applicationId === $application->id));

            return new ApplicationGroupData(
                application: $application,
                environments: $own,
                // Watched rows with a reading only: an unwatched row carries
                // no status, and "no reading yet" is not a problem to triage.
                triageCount: count(array_filter(
                    $own,
                    fn (EnvironmentData $environment) => $environment->watched
                        && $environment->status !== null
                        && ! $environment->status->isHealthy(),
                )),
            );
        }, $this->monitoring->configurableApplications($team));

        return new ApplicationListPageData($groups, count($environments));
    }
}
