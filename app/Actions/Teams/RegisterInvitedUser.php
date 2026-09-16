<?php

namespace App\Actions\Teams;

use App\Data\Teams\AcceptInvitationData;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterInvitedUser
{
    /**
     * Create the guest's account and accept the invitation for them, all
     * inside one transaction locked on the invitation row: two
     * simultaneous submissions of the same registration form must not
     * both pass the "no account yet" check and then race each other into
     * the email's unique constraint — the loser aborts cleanly (409)
     * instead of a raw QueryException.
     */
    public function handle(TeamInvitation $invitation, AcceptInvitationData $data, AcceptInvitation $acceptInvitation): User
    {
        return DB::transaction(function () use ($invitation, $data, $acceptInvitation) {
            $locked = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // An account for this email already exists (created outside
            // this invitation, e.g. it owns another organization, or a
            // concurrent submission of this same form won the race): that
            // person should log in and accept, not register a second
            // account under the same address.
            abort_if(User::where('email', $locked->email)->exists(), 409);

            $user = User::create([
                'name' => $data->name,
                'email' => $locked->email,
                'password' => $data->password,
                'email_verified_at' => now(),
            ]);

            $acceptInvitation->handle($locked, $user);

            return $user;
        });
    }
}
