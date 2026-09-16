<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Applications\AddApplication;
use App\Actions\Applications\DeleteApplication;
use App\Actions\Applications\UpdateApplication;
use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\ApplicationWizardData;
use App\Data\Applications\ConfirmByNameData;
use App\Data\Pages\ApplicationFormPageData;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Team;
use App\Queries\ApplicationDetailQuery;
use App\Queries\ApplicationListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(Team $current_team, ApplicationListQuery $query): Response
    {
        return Inertia::render('monitoring/applications/Index', [
            'page' => $query->handle($current_team),
        ]);
    }

    public function show(Team $current_team, string $application, ApplicationDetailQuery $query): Response
    {
        return Inertia::render('monitoring/applications/Show', [
            'page' => $query->handle($current_team, $application),
        ]);
    }

    public function create(Team $current_team): Response
    {
        Gate::authorize('create', [Application::class, $current_team]);

        return Inertia::render('monitoring/applications/Create', [
            'page' => new ApplicationFormPageData(application: null),
        ]);
    }

    public function store(Team $current_team, ApplicationWizardData $data, AddApplication $addApplication): RedirectResponse
    {
        Gate::authorize('create', [Application::class, $current_team]);

        $application = $addApplication->handle($current_team, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application created.')]);

        return to_route('applications.edit', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function edit(Team $current_team, Application $application): Response
    {
        $this->ensureBelongsToTeam($application, $current_team);
        Gate::authorize('update', $application);

        return Inertia::render('monitoring/applications/Edit', [
            'page' => new ApplicationFormPageData(
                application: ApplicationFormData::from($application),
            ),
        ]);
    }

    public function update(Team $current_team, Application $application, ApplicationFormData $data, UpdateApplication $updateApplication): RedirectResponse
    {
        $this->ensureBelongsToTeam($application, $current_team);
        Gate::authorize('update', $application);

        $updateApplication->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application updated.')]);

        return to_route('applications.edit', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function destroy(Team $current_team, Application $application, ConfirmByNameData $data, DeleteApplication $deleteApplication): RedirectResponse
    {
        $this->ensureBelongsToTeam($application, $current_team);
        Gate::authorize('delete', $application);

        $deleteApplication->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application deleted.')]);

        return to_route('applications.index', ['current_team' => $current_team->slug]);
    }

    /**
     * An application resolved by slug alone doesn't know which organization
     * it belongs to: without this check, an admin of one team could act on
     * another team's application just by guessing its slug.
     */
    private function ensureBelongsToTeam(Application $application, Team $team): void
    {
        abort_unless($application->team_id === $team->id, 404);
    }
}
