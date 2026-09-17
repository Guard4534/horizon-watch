<?php

namespace Tests\Support;

use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AlertTeam
{
    /**
     * @param  list<Environment>  $grants
     */
    public static function member(
        Team $team,
        string $email,
        TeamRole $role = TeamRole::Member,
        MemberVisibility $visibility = MemberVisibility::All,
        bool $alertEmails = true,
        ?Locale $locale = null,
        array $grants = [],
    ): User {
        $user = User::factory()->create(['email' => $email, 'alert_emails' => $alertEmails, 'locale' => $locale]);

        $team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);

        foreach ($grants as $environment) {
            DB::table('environment_user')->insert(['user_id' => $user->id, 'environment_id' => $environment->id]);
        }

        return $user;
    }
}
