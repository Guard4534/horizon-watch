<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Applications\AddApplication;
use App\Actions\Applications\DeleteApplication;
use App\Actions\Applications\UpdateApplication;
use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\ApplicationWizardData;
use App\Data\Applications\ConfirmByNameData;
use App\Data\Applications\EnvironmentFormData;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Queries\ApplicationCreateQuery;
use App\Queries\ApplicationDetailQuery;
use App\Queries\ApplicationEditQuery;
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

    public function create(Team $current_team, ApplicationCreateQuery $query): Response
    {
        Gate::authorize('create', [Application::class, $current_team]);

        return Inertia::render('monitoring/applications/Create', [
            'page' => $query->handle(),
        ]);
    }

    public function store(Team $current_team, ApplicationWizardData $data, AddApplication $addApplication): RedirectResponse
    {
        Gate::authorize('create', [Application::class, $current_team]);
        $this->authorizeCredentialsIfTouched($data, $current_team);

        $application = $addApplication->handle($current_team, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application created.')]);

        return to_route('applications.edit', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function edit(Team $current_team, Application $application, ApplicationEditQuery $query): Response
    {
        Gate::authorize('update', $application);

        return Inertia::render('monitoring/applications/Edit', [
            'page' => $query->handle($application),
        ]);
    }

    public function update(Team $current_team, Application $application, ApplicationFormData $data, UpdateApplication $updateApplication): RedirectResponse
    {
        Gate::authorize('update', $application);

        $updateApplication->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application updated.')]);

        return to_route('applications.edit', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function destroy(Team $current_team, Application $application, ConfirmByNameData $data, DeleteApplication $deleteApplication): RedirectResponse
    {
        Gate::authorize('delete', $application);

        $deleteApplication->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application deleted.')]);

        return to_route('applications.index', ['current_team' => $current_team->slug]);
    }

    /**
     * The wizard creates the environments together with the application, so
     * EnvironmentController's credentials gate has no row to check yet: a
     * transient environment carrying a transient application carrying the
     * team is all EnvironmentPolicy::manageCredentials() reads. "Manage
     * applications" and "manage credentials" are granted to the same roles
     * today, so this changes nothing now — it keeps the wizard from being
     * the one write path that skips the second gate if they ever diverge.
     */
    private function authorizeCredentialsIfTouched(ApplicationWizardData $data, Team $team): void
    {
        $touchesCredentials = collect($data->environments)
            ->contains(fn (EnvironmentFormData $environment): bool => $environment->touchesCredentials());

        if (! $touchesCredentials) {
            return;
        }

        $environment = (new Environment)->setRelation(
            'application',
            (new Application)->setRelation('team', $team),
        );

        Gate::authorize('manageCredentials', $environment);
    }
}
