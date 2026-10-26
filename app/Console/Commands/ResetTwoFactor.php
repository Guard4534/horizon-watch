<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

#[Signature('users:reset-two-factor {email : Email address of the account}')]
#[Description('Turn two-factor authentication off for an account, so it can log in with its password alone and set it up again')]
class ResetTwoFactor extends Command
{
    public function handle(DisableTwoFactorAuthentication $disable): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account uses {$email}.");

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null && $user->two_factor_recovery_codes === null && $user->two_factor_confirmed_at === null) {
            $this->info("{$email} does not use two-factor authentication.");

            return self::SUCCESS;
        }

        $disable($user);

        $this->info("Two-factor authentication is off for {$email}.");

        return self::SUCCESS;
    }
}
