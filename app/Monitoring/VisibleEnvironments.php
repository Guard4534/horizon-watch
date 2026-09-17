<?php

namespace App\Monitoring;

use App\Enums\MemberVisibility;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single place the environment-visibility filter lives (see the phase 2
 * spec, "Visibilità degli ambienti"). Never reimplement this in a page, a
 * controller, or another query: everything that lists or counts
 * environments goes through here.
 */
class VisibleEnvironments
{
    /**
     * Environments of the team visible to the user, with their application
     * eager loaded, ordered by application name then environment name.
     *
     * A user with no membership on the team sees nothing: this should never
     * happen in practice, since every route that reaches here first passes
     * through EnsureTeamMembership.
     *
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
     * Every environment of the team, in the same shape and order as
     * query(), with no visibility filter at all.
     *
     * Deliberately unfiltered, and the only such query in the app: the spec
     * says "la visibilità non è un permesso", so the Applications pages —
     * where the permission decides, not the visibility — list the whole
     * organization to a member who may manage applications. Its one caller
     * is ConfiguredMonitoringRepository, which gates it on
     * TeamPermission::ManageApplications; everything that describes what a
     * member is *watching* must go through query() instead. Never call this
     * from a page, a controller or another query.
     *
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
