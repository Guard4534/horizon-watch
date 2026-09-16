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
     *
     * Clearing the username clears the stored password with it. Basic auth
     * needs both halves, the password can never be read back out to be
     * re-paired with a new username, and leaving it behind would keep the
     * edit page reporting "password set" for a credential nobody can use.
     * Validation keeps the mirror case (a password with no username) from
     * ever reaching here, see EnvironmentFormData::rules().
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

        if ($data->basicAuthUser === null) {
            $attributes['basic_auth_password'] = null;
        } elseif ($data->hasNewPassword()) {
            $attributes['basic_auth_password'] = $data->basicAuthPassword;
        }

        $environment->update($attributes);

        return $environment;
    }
}
