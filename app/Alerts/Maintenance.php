<?php

namespace App\Alerts;

use App\Enums\MaintenanceScope;
use App\Models\Alert;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

final class Maintenance
{
    public static function windowFor(Environment $environment, CarbonImmutable $at): ?MaintenanceWindow
    {
        return MaintenanceWindow::query()
            ->covering($environment)
            ->activeAt($at)
            ->orderByDesc('ends_at')
            ->orderBy('id')
            ->sharedLock()
            ->first();
    }

    /**
     * @param  Builder<Alert>  $alerts
     */
    public static function silence(Builder $alerts, MaintenanceWindow $window, CarbonImmutable $at): int
    {
        $ids = $alerts
            ->open()
            ->where(fn (Builder $elsewhere) => $elsewhere
                ->whereNull('alerts.maintenance_window_id')
                ->orWhere('alerts.maintenance_window_id', '!=', $window->id))
            ->where('alerts.muted_indefinitely', false)
            ->where(fn (Builder $sooner) => $sooner
                ->whereNull('alerts.muted_until')
                ->orWhere('alerts.muted_until', '<', $window->ends_at))
            ->where(fn (Builder $byHand) => $byHand
                ->whereNull('alerts.muted_by')
                ->orWhereNull('alerts.muted_until')
                ->orWhere('alerts.muted_until', '<=', $at->utc()))
            ->orderBy('alerts.environment_id')
            ->orderBy('alerts.metric')
            ->orderBy('alerts.id')
            ->lockForUpdate()
            ->pluck('alerts.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Alert::query()->whereKey($ids)->update([
            'muted_until' => $window->ends_at,
            'muted_by' => null,
            'maintenance_window_id' => $window->id,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function lift(MaintenanceWindow $window): array
    {
        $ids = Alert::query()
            ->open()
            ->where('maintenance_window_id', $window->id)
            ->whereNull('muted_by')
            ->where('muted_indefinitely', false)
            ->orderBy('environment_id')
            ->orderBy('metric')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            Alert::query()->whereKey($ids)->update(['muted_until' => null]);
        }

        return array_values($ids->map(fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * @param  Builder<Alert>  $alerts
     * @return Builder<Alert>
     */
    public static function outside(Builder $alerts, CarbonImmutable $at): Builder
    {
        return $alerts->whereNotExists(fn (QueryBuilder $windows) => $windows
            ->selectRaw('1')
            ->from('maintenance_windows')
            ->join('environments as covered', fn (JoinClause $join) => $join->on('covered.id', '=', 'alerts.environment_id'))
            ->whereColumn('maintenance_windows.team_id', 'alerts.team_id')
            ->where('maintenance_windows.starts_at', '<=', $at->utc())
            ->where('maintenance_windows.ends_at', '>', $at->utc())
            ->whereRaw('maintenance_windows.id is distinct from alerts.maintenance_window_id')
            ->where(fn (QueryBuilder $covering) => $covering
                ->where('maintenance_windows.scope', MaintenanceScope::Organization->value)
                ->orWhereColumn('maintenance_windows.application_id', 'covered.application_id')
                ->orWhereColumn('maintenance_windows.environment_id', 'covered.id')));
    }
}
