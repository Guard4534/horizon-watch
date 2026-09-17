<?php

namespace App\Actions\Settings;

use App\Models\User;

class UpdateAlertEmails
{
    public function handle(User $user, bool $alertEmails): void
    {
        $user->update(['alert_emails' => $alertEmails]);
    }
}
