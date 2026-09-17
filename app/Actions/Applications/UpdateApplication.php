<?php

namespace App\Actions\Applications;

use App\Data\Applications\ApplicationFormData;
use App\Models\Application;

class UpdateApplication
{
    public function handle(Application $application, ApplicationFormData $data): Application
    {
        $application->update([
            'name' => $data->name,
            'host' => $data->host,
        ]);

        return $application;
    }
}
