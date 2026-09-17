<?php

namespace App\Actions\Environments;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;
use LogicException;

class UpdateEnvironment
{
    public function handle(Environment $environment, EnvironmentFormData $data): Environment
    {
        if ($data->basicAuthUser !== null
            && ! $data->hasNewPassword()
            && StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)
            && ! EnvironmentFormData::sameAddress($data->horizonUrl, $environment->horizon_url)) {
            throw new LogicException('The stored password cannot follow the environment to a new address.');
        }

        $attributes = [
            'name' => $data->name,
            'color' => $data->color,
            'horizon_url' => $data->horizonUrl,
            'basic_auth_user' => $data->basicAuthUser,
            'poll_interval_seconds' => $data->pollIntervalSeconds,
            'polling_enabled' => $data->pollingEnabled,
        ];

        if ($data->basicAuthUser === null) {
            $attributes['basic_auth_password'] = null;
        } elseif ($data->hasNewPassword()) {
            $attributes['basic_auth_password'] = $data->basicAuthPassword;
        }

        $environment->update($attributes);

        return $environment;
    }
}
