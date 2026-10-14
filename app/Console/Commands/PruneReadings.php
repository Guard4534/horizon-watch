<?php

namespace App\Console\Commands;

use App\Actions\Teams\DeleteTeamApplications;
use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\EnvironmentSnapshot;
use App\Models\MaintenanceWindow;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Support\ChunkedDelete;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

#[Signature('monitoring:prune {--chunk= : Rows deleted per statement}')]
#[Description('Delete the readings, the resolved alerts and the ended maintenance windows older than their retention periods, what deleted organizations left behind, and replaced webhook secrets past their overlap')]
class PruneReadings extends Command
{
    public function handle(AlertEngine $alerts, DeleteTeamApplications $deleteApplications): int
    {
        $chunk = max(1, (int) ($this->option('chunk') ?? config()->integer('horizon-watch.readings.prune_chunk')));
        $readingCutoff = now()->subDays(config()->integer('horizon-watch.retention_days'));
        $alertCutoff = now()->subDays(config()->integer('horizon-watch.alert_retention_days'));

        $orphans = $alerts->resolveOrphans(CarbonImmutable::now());
        $this->info("Resolved {$orphans} orphaned ".Str::plural('alert', $orphans).'.');

        $this->report('reading', ChunkedDelete::run(
            EnvironmentSnapshot::query()
                ->where('captured_at', '<', $readingCutoff)
                ->whereExists(fn (QueryBuilder $newer) => $newer
                    ->from('environment_snapshots', 'newer')
                    ->whereColumn('newer.environment_id', 'environment_snapshots.environment_id')
                    ->whereRaw('(newer.captured_at, newer.id) > (environment_snapshots.captured_at, environment_snapshots.id)')),
            $chunk,
        ));

        $this->report('alert', ChunkedDelete::run(
            Alert::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $alertCutoff),
            $chunk,
        ));

        $this->report('notification', ChunkedDelete::run(
            AlertNotification::query()->whereNull('alert_id')->where('sent_at', '<', $alertCutoff),
            $chunk,
        ));

        $this->report('maintenance window', ChunkedDelete::run(
            MaintenanceWindow::query()->where('ends_at', '<', $alertCutoff),
            $chunk,
        ));

        $teams = Team::onlyTrashed()->whereHas('applications')->pluck('id');

        foreach ($teams as $teamId) {
            $deleteApplications->handle($teamId);
        }

        $this->info("Purged {$teams->count()} deleted ".Str::plural('organization', $teams->count()).'.');

        $secrets = NotificationSetting::forgetExpiredPreviousSecrets();
        $this->info("Forgot {$secrets} replaced webhook ".Str::plural('secret', $secrets).'.');

        return self::SUCCESS;
    }

    private function report(string $noun, int $total): void
    {
        $this->info("Deleted {$total} ".Str::plural($noun, $total).'.');
    }
}
