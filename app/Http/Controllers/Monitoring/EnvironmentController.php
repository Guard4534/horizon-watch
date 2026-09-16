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
            'page' => $query->handle($current_team, $environment, $request->enum('range', SeriesRange::class) ?? SeriesRange::ThreeHours),
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

    /**
     * "Manage applications" (checked above via create/update) and "manage
     * credentials" are separate permissions in the spec, granted to the
     * same roles today but not guaranteed to stay that way (see
     * EnvironmentPolicy::manageCredentials()). Only consult the second gate
     * when the submission actually changes the credentials — setting or
     * replacing them (visible in the payload) or removing them (only
     * visible against the stored row, see clearsCredentials()) — so editing
     * just the name, color, URL or poll interval never requires it.
     *
     * @param  Application|Environment  $forExisting  The application when
     *                                                creating (no Environment row exists yet — a transient one
     *                                                carrying only the application relation is enough, since that's
     *                                                all the policy method reads) or the environment when updating.
     */
    private function authorizeCredentialsIfTouched(EnvironmentFormData $data, Application|Environment $forExisting): void
    {
        $environment = $forExisting instanceof Environment
            ? $forExisting
            : (new Environment)->setRelation('application', $forExisting);

        if (! $data->touchesCredentials() && ! $this->clearsCredentials($data, $environment)) {
            return;
        }

        Gate::authorize('manageCredentials', $environment);
    }

    /**
     * Whether the submission removes the credentials this environment has
     * on file. Setting or replacing them is visible in the payload, so
     * EnvironmentFormData::touchesCredentials() catches it on its own;
     * removing them is not, because ConvertEmptyStringsToNull turns the
     * cleared username field into null, which is exactly what a form that
     * never had a username sends. Only the stored row tells the two apart,
     * and dropping a credential is as much a credential change as setting
     * one — it is the one case that would otherwise walk past the gate this
     * method exists to apply.
     */
    private function clearsCredentials(EnvironmentFormData $data, Environment $environment): bool
    {
        return $data->basicAuthUser === null
            && ($environment->basic_auth_user !== null || $environment->basic_auth_password !== null);
    }
}
