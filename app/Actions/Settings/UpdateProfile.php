<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Notifications\Auth\EmailChanged;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

class UpdateProfile
{
    public function handle(User $user, string $name, string $email): void
    {
        $previousEmail = $user->email;

        $user->update(['name' => $name, 'email' => $email]);

        if ($user->wasChanged('email')) {
            Notification::route('mail', $previousEmail)
                ->notify((new EmailChanged($previousEmail, $user->email))->locale(App::getLocale()));
        }
    }
}
