<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\EnvironmentSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Signature('monitoring:prune {--chunk= : Rows deleted per statement}')]
#[Description('Delete the readings and the resolved alerts older than their retention periods')]
class PruneReadings extends Command
{
    public function handle(): int
    {
        $chunk = max(1, (int) ($this->option('chunk') ?? config()->integer('horizon-watch.readings.prune_chunk')));
        $readingCutoff = now()->subDays((int) config('horizon-watch.retention_days'));
        $alertCutoff = now()->subDays((int) config('horizon-watch.alert_retention_days'));

        $this->report('reading', $this->prune(
            EnvironmentSnapshot::query()->where('captured_at', '<', $readingCutoff),
            $chunk,
        ));

        $this->report('alert', $this->prune(
            Alert::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $alertCutoff),
            $chunk,
        ));

        $this->report('notification', $this->prune(
            AlertNotification::query()->whereNull('alert_id')->where('sent_at', '<', $alertCutoff),
            $chunk,
        ));

        return self::SUCCESS;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $expired
     */
    private function prune(Builder $expired, int $chunk): int
    {
        $model = $expired->getModel();
        $total = 0;

        do {
            $deleted = $model->newQuery()
                ->whereIn($model->getKeyName(), (clone $expired)->select($model->getKeyName())->limit($chunk))
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    private function report(string $noun, int $total): void
    {
        $this->info("Deleted {$total} ".Str::plural($noun, $total).'.');
    }
}
