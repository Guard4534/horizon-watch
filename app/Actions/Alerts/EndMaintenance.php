<?php

namespace App\Actions\Alerts;

use App\Alerts\Maintenance;
use App\Models\Alert;
use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EndMaintenance
{
    public function handle(MaintenanceWindow $window): bool
    {
        return DB::transaction(function () use ($window): bool {
            $now = CarbonImmutable::now()->startOfSecond();
            $windows = MaintenanceWindow::query()
                ->where('team_id', $window->team_id)
                ->where(fn (Builder $relevant) => $relevant->whereKey($window->id)->orWhere(fn (Builder $active) => $active->activeAt($now)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $windows->find($window->id);

            if ($locked === null) {
                return false;
            }

            $ending = $locked->starts_at->lt($now) && $locked->ends_at->gt($now);
            $lifted = Maintenance::lift($locked);

            if ($ending) {
                $locked->update(['ends_at' => $now]);
            } else {
                $locked->delete();
            }

            if ($lifted !== []) {
                $windows
                    ->reject(fn (MaintenanceWindow $other) => $other->id === $locked->id || ! $other->isActiveAt($now))
                    ->sortByDesc(fn (MaintenanceWindow $other) => $other->ends_at->getTimestamp())
                    ->each(fn (MaintenanceWindow $other) => Maintenance::silence(
                        Alert::query()->whereKey($lifted)->whereIn('environment_id', $other->coveredEnvironments()->select('environments.id')),
                        $other,
                        $now,
                    ));
            }

            return $ending;
        });
    }
}
