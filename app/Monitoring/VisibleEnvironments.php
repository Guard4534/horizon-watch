<?php

namespace App\Monitoring;

use App\Enums\MemberVisibility;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
     * @param  iterable<Membership>  $memberships
     * @return array<int, list<int>|null>
     */
    public function idsForAllOf(Team $team, iterable $memberships): array
    {
        $byKey = [];
        $manual = [];

        foreach ($memberships as $membership) {
            $byKey[$membership->id] = $membership;

            if ($membership->visibility === MemberVisibility::Manual) {
                $manual[] = $membership->user_id;
            }
        }

        if ($byKey === []) {
            return [];
        }

        $names = [];

        foreach ($this->ofTeam($team)->toBase()->get(['environments.id', 'environments.name']) as $environment) {
            $names[(int) $environment->id] = (string) $environment->name;
        }

        $granted = $this->grants(array_keys($names), array_values(array_unique($manual)));
        $ids = [];

        foreach ($byKey as $key => $membership) {
            $ids[$key] = match ($membership->visibility) {
                MemberVisibility::All => null,
                MemberVisibility::NonProduction => array_keys(array_filter($names, fn (string $name) => $name !== 'production')),
                MemberVisibility::Manual => array_values(array_filter(array_keys($names), fn (int $id) => isset($granted[$membership->user_id][$id]))),
            };
        }

        return $ids;
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

    /**
     * @param  list<int>  $environmentIds
     * @param  list<int>  $userIds
     * @return array<int, array<int, true>>
     */
    private function grants(array $environmentIds, array $userIds): array
    {
        if ($environmentIds === [] || $userIds === []) {
            return [];
        }

        $granted = [];

        foreach (DB::table('environment_user')
            ->whereIn('environment_id', $environmentIds)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'environment_id']) as $grant) {
            $granted[(int) $grant->user_id][(int) $grant->environment_id] = true;
        }

        return $granted;
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
