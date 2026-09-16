<?php

namespace App\Http\Middleware;

use App\Data\Auth\AuthUserData;
use App\Enums\AlertState;
use App\Enums\MemberVisibility;
use App\Models\Application;
use App\Monitoring\MonitoringRepository;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? AuthUserData::fromModel($user) : null,
            ],
            'locale' => fn () => app()->getLocale(),
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'openAlertCount' => fn () => $user?->currentTeam
                ? count(app(MonitoringRepository::class)->alerts($user->currentTeam, AlertState::Open))
                : null,
            // The two flags the empty states of the wall, the application
            // list, the alerts and the alert settings choose their wording
            // from. Shared rather than page props because all four need
            // them and none of them has anything else to ask the server
            // for. They are not free: each one costs an indexed membership
            // query on every full Inertia response, including the pages
            // that never read them. Inertia::optional() would remove that
            // and cost a second round-trip on every monitoring page, which
            // is the worse trade.
            //
            // The two answer different questions and neither implies the
            // other: what this member may do, read through
            // ApplicationPolicy so it matches what the routes enforce, and
            // how much of the organization they see. An admin restricted
            // to "manual" gets the button and the restricted wording; an
            // unrestricted viewer gets neither. No membership at all (never
            // reachable behind EnsureTeamMembership) counts as restricted:
            // such a user really does see nothing.
            'canManageApplications' => fn () => $user?->currentTeam
                ? $user->can('create', [Application::class, $user->currentTeam])
                : false,
            'visibilityRestricted' => fn () => $user?->currentTeam
                ? $user->teamVisibility($user->currentTeam) !== MemberVisibility::All
                : false,
        ];
    }
}
