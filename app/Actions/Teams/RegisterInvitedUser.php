<?php

namespace App\Actions\Teams;

use App\Data\Teams\AcceptInvitationData;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterInvitedUser
{
    public function handle(TeamInvitation $invitation, AcceptInvitationData $data, AcceptInvitation $acceptInvitation): User
    {
        return DB::transaction(function () use ($invitation, $data, $acceptInvitation) {
            $locked = TeamInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->firstOrFail();

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
