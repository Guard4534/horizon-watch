<?php

namespace App\Actions\Environments;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Application;
use App\Models\Environment;

class AddEnvironment
{
    public function handle(Application $application, EnvironmentFormData $data): Environment
    {
        return $application->environments()->create([
            'name' => $data->name,
            'color' => $data->color,
            'horizon_url' => $data->horizonUrl,
            'basic_auth_user' => $data->basicAuthUser,
            'basic_auth_password' => $data->basicAuthPassword,
            'poll_interval_seconds' => $data->pollIntervalSeconds,
        ]);
    }
}
