<?php

namespace App\Models;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property int $team_id
 * @property string $email
 * @property TeamRole $role
 * @property MemberVisibility $visibility
 * @property int $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $inviter
 * @property-read User|null $acceptedBy
 */
#[Fillable(['team_id', 'email', 'role', 'visibility', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by', 'revoked_at'])]
class TeamInvitation extends Model
{
    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TeamInvitation $invitation) {
            if (empty($invitation->code)) {
                $invitation->code = Str::random(64);
            }
        });
    }

    public static function lifetimeDays(): int
    {
        return config()->integer('horizon-watch.invitations.expires_days');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @return BelongsToMany<Environment, $this>
     */
    public function environments(): BelongsToMany
    {
        return $this->belongsToMany(Environment::class, 'environment_team_invitation')
            ->whereExists(fn ($query) => $query
                ->from('team_invitations')
                ->whereColumn('team_invitations.id', 'environment_team_invitation.team_invitation_id')
                ->whereColumn('team_invitations.team_id', 'environments.team_id'));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<TeamInvitation>  $query
     * @return Builder<TeamInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'visibility' => MemberVisibility::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
