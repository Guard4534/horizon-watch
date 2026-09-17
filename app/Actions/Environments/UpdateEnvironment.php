<?php

namespace App\Actions\Environments;

use App\Data\Applications\EnvironmentFormData;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;
use LogicException;

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
     * The stored password only ever goes to the address it was saved for.
     * A blank password keeps it only while the scheme, host and port stay
     * the same (a path-only change is fine); a new address needs the
     * password typed again, which validation enforces with a 422
     * (StoredPasswordStaysWithItsAddress), and counts as a credentials
     * change for the gate (EnvironmentFormData::changesCredentialsOf()).
     * Otherwise an admin could point the poller at their own host, wait one
     * poll and point it back. The check below refuses the write for any
     * caller that skipped that validation.
     *
     * "Absent" and "cleared" or "default" are the same thing here, because
     * the two forms that reach this action always send the whole object. A
     * partial PATCH client that omitted basicAuthUser would destroy the
     * credential without meaning to, and one that omitted pollingEnabled
     * would resume a paused collection (its default is true); if such a
     * client is ever added, it has to distinguish the two before calling
     * this.
     *
     * Validation keeps the mirror case (a password with no username) from
     * ever reaching here, see EnvironmentFormData::rules().
     */
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
