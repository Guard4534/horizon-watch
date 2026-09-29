<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Environments\AddEnvironment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Environments\UpdateEnvironment;
use App\Data\Applications\ConfirmByNameData;
use App\Data\Applications\EnvironmentFormData;
use App\Enums\SeriesRange;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Queries\EnvironmentCreateQuery;
use App\Queries\EnvironmentDetailQuery;
use App\Queries\EnvironmentEditQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
    public function show(Request $request, Team $current_team, string $environment, EnvironmentDetailQuery $query): Response
    {
        return Inertia::render('monitoring/environments/Show', [
            'page' => $query->handle($current_team, $request->user(), $environment, $request->enum('range', SeriesRange::class) ?? SeriesRange::ThreeHours),
        ]);
    }

    public function create(Team $current_team, Application $application, EnvironmentCreateQuery $query): Response
    {
        Gate::authorize('create', [Environment::class, $application]);

        return Inertia::render('monitoring/environments/Create', [
            'page' => $query->handle($application),
        ]);
    }

    public function store(Team $current_team, Application $application, EnvironmentFormData $data, AddEnvironment $addEnvironment): RedirectResponse
    {
        Gate::authorize('create', [Environment::class, $application]);
        $this->authorizeCredentialsIfTouched($data, $application);

        $addEnvironment->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment created.')]);

        return to_route('applications.show', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function edit(Team $current_team, Environment $environment, EnvironmentEditQuery $query): Response
    {
        Gate::authorize('update', $environment);

        return Inertia::render('monitoring/environments/Edit', [
            'page' => $query->handle($environment),
        ]);
    }

    public function update(Team $current_team, Environment $environment, EnvironmentFormData $data, UpdateEnvironment $updateEnvironment): RedirectResponse
    {
        Gate::authorize('update', $environment);
        $this->authorizeCredentialsIfTouched($data, $environment);

        $updateEnvironment->handle($environment, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment updated.')]);

        return to_route('environments.edit', ['current_team' => $current_team->slug, 'environment' => $environment->slug]);
    }

    public function destroy(Team $current_team, Environment $environment, ConfirmByNameData $data, DeleteEnvironment $deleteEnvironment): RedirectResponse
    {
        Gate::authorize('delete', $environment);

        $application = $environment->application;

        $deleteEnvironment->handle($environment, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment deleted.')]);

        return to_route('applications.show', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    private function authorizeCredentialsIfTouched(EnvironmentFormData $data, Application|Environment $forExisting): void
    {
        $environment = $forExisting instanceof Environment
            ? $forExisting
            : (new Environment)->setRelation('application', $forExisting);

        if (! $data->changesCredentialsOf($environment)) {
            return;
        }

        Gate::authorize('manageCredentials', $environment);
    }
}
