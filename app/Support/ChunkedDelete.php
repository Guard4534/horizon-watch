<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ChunkedDelete
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows
     */
    public static function run(Builder $rows, int $chunk, ?float $budgetSeconds = null): int
    {
        $model = $rows->getModel();
        $deadline = $budgetSeconds === null ? null : microtime(true) + $budgetSeconds;
        $total = 0;

        do {
            $deleted = $model->newQuery()
                ->whereIn($model->getKeyName(), (clone $rows)->select($model->getKeyName())->limit(max(1, $chunk)))
                ->delete();

            $total += $deleted;
        } while ($deleted > 0 && ($deadline === null || microtime(true) < $deadline));

        return $total;
    }
}
