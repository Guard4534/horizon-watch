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
            // A blank submission ("" from an untouched input) means "no
            // password", not a literal empty-string credential.
            'basic_auth_password' => $data->hasNewPassword() ? $data->basicAuthPassword : null,
            'poll_interval_seconds' => $data->pollIntervalSeconds,
        ]);
    }
}
