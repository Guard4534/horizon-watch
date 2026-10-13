<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('users:instance-admin {email : Email address of the account} {--revoke : Take the instance admin role away instead of granting it}')]
#[Description('Grant or revoke the instance admin role, which lets an account create organizations')]
class SetInstanceAdmin extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $revoke = (bool) $this->option('revoke');

        return DB::transaction(function () use ($email, $revoke) {
            $admins = User::query()->where('is_instance_admin', true)->lockForUpdate()->pluck('id');

            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $this->error("No account uses {$email}.");

                return self::FAILURE;
            }

            if ($user->is_instance_admin === ! $revoke) {
                $this->info($revoke ? "{$email} is not an instance admin." : "{$email} is already an instance admin.");

                return self::SUCCESS;
            }

            if ($revoke && $admins->diff([$user->id])->isEmpty()) {
                $this->error("{$email} is the last instance admin: grant the role to someone else first.");

                return self::FAILURE;
            }

            $user->forceFill(['is_instance_admin' => ! $revoke])->save();

            $this->info($revoke ? "{$email} is no longer an instance admin." : "{$email} is now an instance admin.");

            return self::SUCCESS;
        });
    }
}
