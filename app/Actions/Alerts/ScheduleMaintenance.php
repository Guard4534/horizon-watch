<?php

namespace App\Actions\Alerts;

use App\Alerts\Maintenance;
use App\Enums\MaintenanceScope;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleMaintenance
{
    public function handle(Team $team, User $by, Application|Environment|null $target, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $reason): MaintenanceWindow
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $timezone = config()->string('app.timezone');
        $startsAt = $startsAt->setTimezone($timezone)->startOfSecond()->max($now)->setTimezone($timezone);
        $endsAt = $endsAt->setTimezone($timezone)->startOfSecond();

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['endsAt' => __('The end must come after the start.')]);
        }

        $maxDays = config()->integer('horizon-watch.alerts.maintenance_max_days');

        if ($startsAt->addDays($maxDays)->lt($endsAt)) {
            throw ValidationException::withMessages(['endsAt' => __('A maintenance window lasts at most :days days.', ['days' => $maxDays])]);
        }

        return DB::transaction(function () use ($team, $by, $target, $startsAt, $endsAt, $reason, $now): MaintenanceWindow {
            $window = new MaintenanceWindow([
                'scope' => match (true) {
                    $target instanceof Environment => MaintenanceScope::Environment,
                    $target instanceof Application => MaintenanceScope::Application,
                    default => MaintenanceScope::Organization,
                },
                'application_id' => $target instanceof Application ? $target->id : null,
                'environment_id' => $target instanceof Environment ? $target->id : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                'created_by' => $by->id,
            ]);
            $window->team()->associate($team);
            $window->save();

            if ($window->isActiveAt($now)) {
                Maintenance::silence(Alert::query()->whereIn('environment_id', $window->coveredEnvironments()->select('environments.id')), $window, $now);
            }

            return $window;
        });
    }
}
