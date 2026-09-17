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
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
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
            'canManageApplications' => fn () => $user?->currentTeam
                ? $user->can('create', [Application::class, $user->currentTeam])
                : false,
            'visibilityRestricted' => fn () => $user?->currentTeam
                ? $user->teamVisibility($user->currentTeam) !== MemberVisibility::All
                : false,
            'organizationHasEnvironments' => fn () => $user?->currentTeam
                ? $user->currentTeam->environments()->exists()
                : false,
        ];
    }
}
