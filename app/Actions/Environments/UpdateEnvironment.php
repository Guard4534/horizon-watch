<?php

namespace App\Actions\Environments;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Environment;

class UpdateEnvironment
{
    /**
     * Update the environment. The password key is only included in the
     * update when the form actually sent a real one (hasNewPassword():
     * neither absent nor blank): omitting it (rather than writing back the
     * decrypted value when absent) means the encrypted column already in
     * the database is never read out and rewritten.
     */
    public function handle(Environment $environment, EnvironmentFormData $data): Environment
    {
        $attributes = [
            'name' => $data->name,
            'color' => $data->color,
            'horizon_url' => $data->horizonUrl,
            'basic_auth_user' => $data->basicAuthUser,
            'poll_interval_seconds' => $data->pollIntervalSeconds,
        ];

        if ($data->hasNewPassword()) {
            $attributes['basic_auth_password'] = $data->basicAuthPassword;
        }

        $environment->update($attributes);

        return $environment;
    }
}
