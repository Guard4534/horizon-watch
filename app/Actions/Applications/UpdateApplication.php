<?php

namespace App\Actions\Applications;

use App\Data\Applications\ApplicationFormData;
use App\Models\Application;

class UpdateApplication
{
    /**
     * Update the application's name and host. The slug is left untouched
     * on purpose: it's immutable after creation (see Application::boot()),
     * so a rename never breaks an environment's slug or a bookmarked route
     * (phase 2 spec, decision on renames).
     */
    public function handle(Application $application, ApplicationFormData $data): Application
    {
        $application->update([
            'name' => $data->name,
            'host' => $data->host,
        ]);

        return $application;
    }
}
