<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Environments\AddEnvironment;
use App\Actions\Environments\DeleteEnvironment;
use App\Actions\Environments\UpdateEnvironment;
use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\ConfirmByNameData;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Applications\EnvironmentSummaryData;
use App\Data\Pages\EnvironmentFormPageData;
use App\Enums\EnvironmentColor;
use App\Enums\SeriesRange;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Queries\EnvironmentDetailQuery;
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

    public function create(Team $current_team, Application $application): Response
    {
        $this->ensureBelongsToTeam($application, $current_team);
        Gate::authorize('create', [Environment::class, $application]);

        return Inertia::render('monitoring/environments/Create', [
            'page' => new EnvironmentFormPageData(
                environment: null,
                application: ApplicationFormData::from($application),
                colors: EnvironmentColor::options(),
                hasPassword: false,
            ),
        ]);
    }

    public function store(Team $current_team, Application $application, EnvironmentFormData $data, AddEnvironment $addEnvironment): RedirectResponse
    {
        $this->ensureBelongsToTeam($application, $current_team);
        Gate::authorize('create', [Environment::class, $application]);

        $addEnvironment->handle($application, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment created.')]);

        return to_route('applications.show', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    public function edit(Team $current_team, Environment $environment): Response
    {
        $this->ensureEnvironmentBelongsToTeam($environment, $current_team);
        Gate::authorize('update', $environment);

        return Inertia::render('monitoring/environments/Edit', [
            'page' => new EnvironmentFormPageData(
                environment: new EnvironmentSummaryData(
                    name: $environment->name,
                    color: $environment->color,
                    horizonUrl: $environment->horizon_url,
                    basicAuthUser: $environment->basic_auth_user,
                    pollIntervalSeconds: $environment->poll_interval_seconds,
                ),
                application: ApplicationFormData::from($environment->application),
                colors: EnvironmentColor::options(),
                // Presence check only: never reads the decrypted value.
                hasPassword: $environment->basic_auth_password !== null,
            ),
        ]);
    }

    public function update(Team $current_team, Environment $environment, EnvironmentFormData $data, UpdateEnvironment $updateEnvironment): RedirectResponse
    {
        $this->ensureEnvironmentBelongsToTeam($environment, $current_team);
        Gate::authorize('update', $environment);

        $updateEnvironment->handle($environment, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment updated.')]);

        return to_route('environments.edit', ['current_team' => $current_team->slug, 'environment' => $environment->slug]);
    }

    public function destroy(Team $current_team, Environment $environment, ConfirmByNameData $data, DeleteEnvironment $deleteEnvironment): RedirectResponse
    {
        $this->ensureEnvironmentBelongsToTeam($environment, $current_team);
        Gate::authorize('delete', $environment);

        $application = $environment->application;

        $deleteEnvironment->handle($environment, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Environment deleted.')]);

        return to_route('applications.show', ['current_team' => $current_team->slug, 'application' => $application->slug]);
    }

    /**
     * See ApplicationController::ensureBelongsToTeam(): same reason, for the
     * application a new environment is being attached to.
     */
    private function ensureBelongsToTeam(Application $application, Team $team): void
    {
        abort_unless($application->team_id === $team->id, 404);
    }

    /**
     * An environment resolved by slug alone doesn't know which organization
     * it belongs to (it only points at its application); without this check
     * an admin of one team could act on another team's environment just by
     * guessing its slug.
     */
    private function ensureEnvironmentBelongsToTeam(Environment $environment, Team $team): void
    {
        abort_unless($environment->application->team_id === $team->id, 404);
    }
}
