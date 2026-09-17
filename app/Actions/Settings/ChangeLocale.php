<?php

namespace App\Actions\Settings;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

class ChangeLocale
{
    public function handle(Session $session, ?User $user, Locale $locale): void
    {
        $session->put('locale', $locale->value);

        $user?->update(['locale' => $locale]);
    }
}
