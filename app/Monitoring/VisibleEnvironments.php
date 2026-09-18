<?php

namespace App\Monitoring;

use App\Enums\MemberVisibility;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class VisibleEnvironments
{
    /**
     * @return Builder<Environment>
     */
    public function query(Team $team, User $user): Builder
    {
        $membership = $this->membership($team, $user);

        if (! $membership) {
            return $this->ofTeam($team)->whereRaw('1 = 0');
        }

        return $this->constrained($team, $membership);
    }

    public function seesEverything(Team $team, User $user): bool
    {
        return $this->membership($team, $user)?->visibility === MemberVisibility::All;
    }

    public function sees(Team $team, User $user, ?int $environmentId): bool
    {
        return $environmentId === null
            ? $this->seesEverything($team, $user)
            : $this->query($team, $user)->whereKey($environmentId)->exists();
    }

    /**
     * @return list<int>|null
     */
    public function idsFor(Team $team, Membership $membership): ?array
    {
        if ($membership->visibility === MemberVisibility::All) {
            return null;
        }

        return array_values(array_map(intval(...), $this->constrained($team, $membership)->pluck('environments.id')->all()));
    }

    /**
     * @return Builder<Environment>
     */
    public function ofTeam(Team $team): Builder
    {
        return Environment::query()
            ->whereHas('application', fn ($applications) => $applications->where('team_id', $team->id))
            ->with('application')
            ->join('applications', 'applications.id', '=', 'environments.application_id')
            ->orderBy('applications.name')
            ->orderBy('environments.name')
            ->select('environments.*');
    }

    private function membership(Team $team, User $user): ?Membership
    {
        return $user->teamMemberships()->where('team_id', $team->id)->first();
    }

    /**
     * @return Builder<Environment>
     */
    private function constrained(Team $team, Membership $membership): Builder
    {
        $query = $this->ofTeam($team);

        return match ($membership->visibility) {
            MemberVisibility::All => $query,
            MemberVisibility::NonProduction => $query->where('environments.name', '!=', 'production'),
            MemberVisibility::Manual => $query->whereIn('environments.id', function ($environments) use ($membership) {
                $environments->select('environment_id')
                    ->from('environment_user')
                    ->where('user_id', $membership->user_id);
            }),
        };
    }
}
