<?php

namespace App\Monitoring;

use App\Enums\MemberVisibility;
use App\Models\Environment;
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
        $query = $this->ofTeam($team);

        $membership = $user->teamMemberships()->where('team_id', $team->id)->first();

        if (! $membership) {
            return $query->whereRaw('1 = 0');
        }

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
}
