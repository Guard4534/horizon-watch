<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Alerts\EndMaintenance;
use App\Actions\Alerts\ScheduleMaintenance;
use App\Data\Alerts\MaintenanceWindowInputData;
use App\Data\Alerts\SilenceEnvironmentData;
use App\Enums\MaintenanceScope;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use App\Models\Team;
use App\Monitoring\VisibleEnvironments;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MaintenanceWindowController extends Controller
{
    public function __construct(private readonly VisibleEnvironments $visible) {}

    public function store(Request $request, Team $current_team, MaintenanceWindowInputData $data, ScheduleMaintenance $scheduleMaintenance): RedirectResponse
    {
        $target = $this->target($request, $current_team, $data);

        Gate::authorize('create', [MaintenanceWindow::class, $current_team, $target]);

        $scheduleMaintenance->handle(
            $current_team,
            $request->user(),
            $target,
            CarbonImmutable::parse($data->startsAt),
            CarbonImmutable::parse($data->endsAt),
            $data->reason,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window scheduled.')]);

        return back();
    }

    public function silence(Request $request, Team $current_team, Environment $environment, ScheduleMaintenance $scheduleMaintenance): RedirectResponse
    {
        abort_unless($this->visible->sees($current_team, $request->user(), $environment->id), 404);
        Gate::authorize('create', [MaintenanceWindow::class, $current_team, $environment]);

        $now = CarbonImmutable::now();

        $scheduleMaintenance->handle(
            $current_team,
            $request->user(),
            $environment,
            $now,
            $now->addMinutes(SilenceEnvironmentData::validateAndCreate($request->all())->minutes),
            null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alerts silenced.')]);

        return back();
    }

    public function destroy(Request $request, Team $current_team, string $window, EndMaintenance $endMaintenance): RedirectResponse
    {
        abort_unless(ctype_digit($window), 404);

        $found = MaintenanceWindow::query()->where('team_id', $current_team->id)->whereKey((int) $window)->first();

        abort_if($found === null, 404);
        Gate::authorize('delete', $found);

        $ended = $endMaintenance->handle($found);

        Inertia::flash('toast', ['type' => 'success', 'message' => $ended
            ? __('Maintenance window ended.')
            : __('Maintenance window deleted.')]);

        return back();
    }

    private function target(Request $request, Team $team, MaintenanceWindowInputData $data): Application|Environment|null
    {
        $target = match ($data->scope) {
            MaintenanceScope::Organization => null,
            MaintenanceScope::Application => $this->visible->seesEverything($team, $request->user())
                ? $team->applications()->where('slug', $data->target)->first()
                : null,
            MaintenanceScope::Environment => $this->visible->query($team, $request->user())
                ->where('environments.slug', $data->target)
                ->first(),
        };

        if ($data->scope !== MaintenanceScope::Organization && $target === null) {
            throw ValidationException::withMessages(['target' => __('Choose an application or an environment you can see.')]);
        }

        return $target;
    }
}
