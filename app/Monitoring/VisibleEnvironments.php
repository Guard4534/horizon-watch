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
        $membership = $user->membershipOf($team);

        if (! $membership) {
            return $this->ofTeam($team)->whereRaw('1 = 0');
        }

        return $this->constrained($team, $membership);
    }

    public function seesEverything(Team $team, User $user): bool
    {
        return $user->membershipOf($team)?->visibility === MemberVisibility::All;
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

        $production = [];

        foreach ($this->ofTeam($team)->toBase()->get(['environments.id', 'environments.is_production']) as $environment) {
            $production[(int) $environment->id] = (bool) $environment->is_production;
        }

        $granted = $this->grants(array_keys($production), array_values(array_unique($manual)));
        $ids = [];

        foreach ($byKey as $key => $membership) {
            $ids[$key] = match ($membership->visibility) {
                MemberVisibility::All => null,
                MemberVisibility::NonProduction => array_keys(array_filter($production, fn (bool $isProduction) => ! $isProduction)),
                MemberVisibility::Manual => array_values(array_filter(array_keys($production), fn (int $id) => isset($granted[$membership->user_id][$id]))),
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

    /**
     * @return Builder<Environment>
     */
    private function constrained(Team $team, Membership $membership): Builder
    {
        $query = $this->ofTeam($team);

        return match ($membership->visibility) {
            MemberVisibility::All => $query,
            MemberVisibility::NonProduction => $query->where('environments.is_production', false),
            MemberVisibility::Manual => $query->whereIn('environments.id', function ($environments) use ($membership) {
                $environments->select('environment_id')
                    ->from('environment_user')
                    ->where('user_id', $membership->user_id);
            }),
        };
    }
}
