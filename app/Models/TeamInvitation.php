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

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TeamInvitation $invitation) {
            if (empty($invitation->code)) {
                $invitation->code = Str::random(64);
            }
        });
    }

    /**
     * Get the team that the invitation belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Get the user who accepted the invitation, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * Get the environments picked for a "manual" visibility invite. Only
     * meaningful before acceptance: AcceptInvitation copies these into
     * environment_user, where the rest of the app reads them from.
     *
     * Scoped to the invitation's own organization, for the reason
     * Membership::visibleEnvironments() was scoped in aec7581: the write
     * path validates every id against the team twice already
     * (InviteMemberData's exists() rule, then InviteMember only syncing for
     * a manual visibility), but a relation that reads unscoped is one
     * forgotten check away from showing an invitee another organization's
     * environment, and whoever reads the two relations side by side should
     * not have to work out why only one of them is safe.
     *
     * Scoped by columns, not by "$this->team_id" like its sibling: this
     * relation IS eager-loaded (InvitationController::show()), and Eloquent
     * builds an eager-load constraint from a *fresh* model instance, whose
     * team_id is null — the captured-attribute version would quietly match
     * nothing and the invitation page would name no environments at all.
     * The invitation the pivot row belongs to is in the query, so the
     * comparison can be made there, and environments.team_id (see the
     * migration) is what makes it one subquery instead of a join.
     *
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
     * Normalize the address on the way in, for the same reason User does:
     * the invitation's email is compared exactly against users.email by
     * RegisterInvitedUser and by InvitationController, so both sides have
     * to be canonical or an invitation typed in mixed case can never find
     * the account it belongs to. See User::email().
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }

    /**
     * Determine if the invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Determine if the invitation has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Determine if the invitation is still open: not accepted, not revoked,
     * not expired.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->isExpired();
    }

    /**
     * Determine if the invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Scope a query to invitations that are still open (see isPending()).
     *
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
     * Get the attributes that should be cast.
     *
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

    // No getRouteKeyName() override on purpose: nothing binds this model by
    // code any more. The public flow takes a "string $code" it looks up
    // itself, the notification builds the link from ->code, and the panel's
    // own routes say "{invitation:id}". Returning 'code' here would put a
    // credential back into the next "{invitation}" URL by default.
}
