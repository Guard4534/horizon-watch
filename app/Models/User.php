<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\Locale;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Locale|null $locale
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['name', 'email', 'password', 'email_verified_at', 'current_team_id', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

    /**
     * Normalize the address on the way in. Every comparison the panel makes
     * on it is exact — Fortify's user lookup, RegisterInvitedUser's race
     * guard, InvitationController's "this address already has an account"
     * check and the users.email unique index — and PostgreSQL has no
     * case-insensitive collation to hide the difference. One mutator is
     * what makes all of them agree: without it an account created at
     * /setup as "Admin@Example.com" is invisible to an invitation
     * addressed to "admin@example.com", and its owner is told to sign in
     * with a spelling no row holds.
     *
     * The submitted side of the login, password-reset and
     * profile-information forms is already lowercased by
     * config('fortify.lowercase_usernames'), which is why this side is the
     * only one missing. Deliberately set-only: reading a row back must
     * show what is stored, not a value the query could not have found.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'locale' => Locale::class,
        ];
    }
}
