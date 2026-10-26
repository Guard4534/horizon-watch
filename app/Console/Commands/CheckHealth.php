<?php

namespace App\Console\Commands;

use App\Monitoring\PanelHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('horizon-watch:health {--role= : Check only the scheduler or the worker running in this container}')]
#[Description('Check the database, the scheduler and the queues of the panel, and fail when one of them is not keeping up')]
class CheckHealth extends Command
{
    public function handle(PanelHealth $health): int
    {
        $role = $this->option('role');

        if (! in_array($role, [null, 'scheduler', 'worker'], true)) {
            $this->components->error('The role must be scheduler or worker.');

            return self::INVALID;
        }

        $database = $health->databaseIsUp();
        $checks = ['database' => [$database, $database ? 'reachable' : 'unreachable']];

        if ($database && $role === 'worker') {
            $seconds = $health->workerSecondsAgo();
            $checks['worker'] = [
                $seconds !== null && $seconds <= config()->integer('horizon-watch.health.worker_late_seconds'),
                $this->ago('looked for a job', $seconds),
            ];
        }

        if ($database && $role !== 'worker') {
            $report = $health->report(countFailedJobs: false);
            $checks['scheduler'] = [! $report->schedulerLate(), $this->ago('ran the due polls and alert repetitions', $report->schedulerSecondsAgo)];

            if ($role === null) {
                $oldest = $report->oldestWaitSeconds();
                $checks['queues'] = [! $report->backlogLate(), $oldest === null ? 'nothing waiting' : "the oldest job has waited {$oldest} s"];
            }
        }

        foreach ($checks as $name => [$ok, $detail]) {
            $this->components->twoColumnDetail($name, ($ok ? '<fg=green>ok</>' : '<fg=red>failing</>').' · '.$detail);
        }

        return collect($checks)->every(fn (array $check) => $check[0]) ? self::SUCCESS : self::FAILURE;
    }

    private function ago(string $what, ?int $seconds): string
    {
        return $seconds === null ? "never {$what}" : "{$what} {$seconds} s ago";
    }
}
