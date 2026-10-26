<?php

namespace App\Http\Middleware;

use App\Data\Auth\AuthUserData;
use App\Data\Monitoring\PanelHealthData;
use App\Models\Application;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\PanelHealth;
use App\Monitoring\VisibleEnvironments;
use App\Support\MailStatus;
use App\Support\Version;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $teams = null;
        $userTeams = function () use ($user, &$teams) {
            return $teams ??= $user?->toUserTeams() ?? collect();
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? AuthUserData::fromModel($user) : null,
            ],
            'locale' => fn () => app()->getLocale(),
            'currentTeam' => fn () => $user?->currentTeam
                ? $userTeams()->firstWhere('isCurrent', true) ?? $user->toUserTeam($user->currentTeam)
                : null,
            'teams' => $userTeams,
            'canCreateOrganizations' => fn () => $user?->can('create', Team::class) ?? false,
            'openAlertCount' => fn () => $user?->currentTeam
                ? app(MonitoringRepository::class)->alertCounts($user->currentTeam)->open
                : null,
            'canManageApplications' => fn () => $user?->currentTeam
                ? $user->can('create', [Application::class, $user->currentTeam])
                : false,
            'visibilityRestricted' => fn () => $user?->currentTeam
                ? ! app(VisibleEnvironments::class)->seesEverything($user->currentTeam, $user)
                : false,
            'organizationHasEnvironments' => fn () => $user?->currentTeam
                ? $user->currentTeam->environments()->exists()
                : false,
            'version' => $user ? Version::current() : null,
            'mailConfigured' => fn () => $user ? MailStatus::isConfigured() : null,
            'panelHealth' => fn () => $user
                ? PanelHealthData::fromReport(app(PanelHealth::class)->report(countFailedJobs: false))
                : null,
        ];
    }
}
