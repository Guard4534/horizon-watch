<?php

namespace App\Concerns;

use App\Data\TeamPermissions;
use App\Data\UserTeam;
use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

trait HasTeams
{
    /**
     * Get all of the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the teams the user owns.
     *
     * @return HasManyThrough<Team, Membership, $this>
     */
    public function ownedTeams(): HasManyThrough
    {
        return $this->hasManyThrough(
            Team::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'team_id',
        )->where('team_members.role', TeamRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current team.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the user's personal team.
     */
    public function personalTeam(): ?Team
    {
        return $this->ownedTeams()
            ->where('teams.is_personal', true)
            ->first();
    }

    /**
     * Switch to the given team.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->setRelation('currentTeam', $team);

        URL::defaults(['current_team' => $team->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    /**
     * Determine if the user is the owner of the given team.
     */
    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    /**
     * Get the user's role on the given team.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return $this->teamMemberships()
            ->where('team_id', $team->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's environment visibility on the given team.
     *
     * Visibility is an axis of its own: a role says what someone may do,
     * this says how much of the organization they see. An admin can hold
     * "manual" visibility and an owner "non_production", so nothing may
     * infer one from the other.
     */
    public function teamVisibility(Team $team): ?MemberVisibility
    {
        return $this->teamMemberships()
            ->where('team_id', $team->id)
            ->first()
            ?->visibility;
    }

    /**
     * Get the user's teams as a collection of UserTeam objects.
     *
     * @return Collection<int, UserTeam>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        return $this->teams()
            ->get()
            ->map(fn (Team $team) => ! $includeCurrent && $this->isCurrentTeam($team) ? null : $this->toUserTeam($team))
            ->filter()
            ->values();
    }

    /**
     * Get the user's team as a UserTeam object.
     */
    public function toUserTeam(Team $team): UserTeam
    {
        $role = $this->teamRole($team);

        return new UserTeam(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: $role?->value,
            roleLabel: $role === null ? null : self::roleLabel($role),
            isCurrent: $this->isCurrentTeam($team),
        );
    }

    /**
     * The one label of a role tag in the panel, shared by every producer of
     * one: this trait's toUserTeam(), TeamController::edit() and
     * MembersQuery. Static because none of them is "a user" — two build the
     * label of somebody else's membership.
     *
     * The format is the lowercase enum value. The mockup writes the role
     * tags that way (its members table, its permission-matrix header and
     * its invite radios all read "admin", "member", "viewer"), and those
     * three words read the same in both languages, which is why
     * TeamRole::label() stays out of __() and off the container — see that
     * docblock. Only the owner gets a sentence, and only the owner is
     * translated: "Owner · admin" is the mockup's own wording (t.roleAdmin),
     * because the owner holds every admin permission plus deleting the
     * organization.
     *
     * TeamRole::label() keeps the capitalised prose form, for the two places
     * a role sits inside a sentence rather than in a tag: the invitation
     * email and the invitation card.
     */
    public static function roleLabel(TeamRole $role): string
    {
        return $role === TeamRole::Owner ? __('Owner · admin') : $role->value;
    }

    /**
     * Get the standard permissions for a team as a TeamPermissions object.
     *
     * Read through the Gate, one ability per flag, so the page's buttons and
     * the server's refusals cannot answer differently. Asking
     * TeamRole::hasPermission() directly used to skip the Policies' extra
     * clauses: TeamPolicy::delete() also refuses a personal team, which the
     * Vue template then had to patch back in by hand.
     */
    public function toTeamPermissions(Team $team): TeamPermissions
    {
        $gate = Gate::forUser($this);

        return new TeamPermissions(
            canUpdateTeam: $gate->allows('update', $team),
            canDeleteTeam: $gate->allows('delete', $team),
            canUpdateMember: $gate->allows('updateMember', $team),
            canRemoveMember: $gate->allows('removeMember', $team),
            canCreateInvitation: $gate->allows('inviteMember', $team),
        );
    }

    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->when($excluding, fn ($query) => $query->where('teams.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the team.
     */
    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
