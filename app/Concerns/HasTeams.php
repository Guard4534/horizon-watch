<?php

namespace App\Concerns;

use App\Data\Teams\UserTeamData;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Support\Memberships;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasTeams
{
    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members', 'user_id', 'team_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->update(['current_team_id' => $team->id]);
        $this->switchTeamForRequest($team);

        return true;
    }

    public function switchTeamForRequest(Team $team): void
    {
        $this->setAttribute('current_team_id', $team->id);
        $this->syncOriginalAttribute('current_team_id');
        $this->setRelation('currentTeam', $team);

        URL::defaults(['current_team' => $team->slug]);
    }

    public function belongsToTeam(Team $team): bool
    {
        return ! $team->trashed() && $this->membershipOf($team) !== null;
    }

    public function membershipOf(Team $team): ?Membership
    {
        return app(Memberships::class)->of($this, $team);
    }

    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }

    public function ownsTeam(Team $team): bool
    {
        return $this->teamRole($team) === TeamRole::Owner;
    }

    public function teamRole(Team $team): ?TeamRole
    {
        return $this->membershipOf($team)?->role;
    }

    /**
     * @return Collection<int, UserTeamData>
     */
    public function toUserTeams(bool $includeCurrent = false): Collection
    {
        return $this->teams()
            ->get()
            ->map(fn (Team $team) => ! $includeCurrent && $this->isCurrentTeam($team) ? null : $this->toUserTeam($team))
            ->filter()
            ->values();
    }

    public function toUserTeam(Team $team): UserTeamData
    {
        $role = $this->teamRole($team);

        return new UserTeamData(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            role: $role,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentTeam($team),
        );
    }

    public function fallbackTeam(?Team $excluding = null): ?Team
    {
        return $this->teams()
            ->when($excluding, fn ($query) => $query->where('teams.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(teams.name)')
            ->first();
    }

    public function currentOrFallbackTeam(): ?Team
    {
        if ($this->currentTeam !== null) {
            return $this->currentTeam;
        }

        $fallback = $this->fallbackTeam();

        if ($fallback !== null) {
            $this->switchTeam($fallback);
        }

        return $fallback;
    }

    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }
}
