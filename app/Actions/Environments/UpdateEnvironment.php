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
     * Clearing the username clears the stored password with it, which is
     * what the edit page promises: basic auth needs both halves, so a
     * password left behind alone could never authenticate, could never be
     * shown, and would keep the page reporting "password set" for a
     * credential nobody can use. Sending a *different* username with a
     * blank password deliberately keeps the stored password and re-pairs it
     * with the new username — the page says "leave blank to keep it", and
     * that is the only reading of a blank field it offers.
     *
     * "Absent" and "cleared" are the same thing here, because the two forms
     * that reach this action always send the whole object. A partial PATCH
     * client that omitted basicAuthUser would destroy the credential
     * without meaning to; if one is ever added, it has to distinguish the
     * two before calling this.
     *
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
