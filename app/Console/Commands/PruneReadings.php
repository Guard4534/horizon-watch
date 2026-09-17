<?php

namespace App\Console\Commands;

use App\Models\EnvironmentSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('monitoring:prune {--chunk=10000 : Readings deleted per statement}')]
#[Description('Delete the readings older than the retention period')]
class PruneReadings extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('horizon-watch.retention_days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $total = 0;

        do {
            $deleted = EnvironmentSnapshot::query()
                ->whereIn('id', EnvironmentSnapshot::query()
                    ->select('id')
                    ->where('captured_at', '<', $cutoff)
                    ->limit($chunk))
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Deleted {$total} ".Str::plural('reading', $total).'.');

        return self::SUCCESS;
    }
}
