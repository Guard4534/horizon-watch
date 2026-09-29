<?php

namespace App\Monitoring;

use App\Enums\SeriesRange;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class StoredReadings
{
    private const SERIES_ORIGIN = '1970-01-01 00:00:00+00';

    /**
     * @param  iterable<Environment>  $environments
     * @return array<int, EnvironmentState|null>
     */
    public function latestFor(iterable $environments): array
    {
        $latest = [];

        foreach ($environments as $environment) {
            $latest[$environment->id] = null;
        }

        if ($latest === []) {
            return [];
        }

        $snapshot = EnvironmentSnapshot::query()
            ->select(['pending', 'max_wait_seconds', 'jobs_per_minute', 'failed_in_window', 'failed_window_minutes', 'failed_last_hour', 'workers', 'node_count'])
            ->whereColumn('environment_snapshots.environment_id', 'environment_states.environment_id')
            ->whereRaw("(environment_snapshots.environment_id, environment_snapshots.captured_at) <= (environment_states.environment_id, 'infinity'::timestamptz)")
            ->orderByDesc('environment_snapshots.environment_id')
            ->orderByDesc('environment_snapshots.captured_at')
            ->orderByDesc('environment_snapshots.id')
            ->limit(1);

        $states = EnvironmentState::query()
            ->select('environment_states.*')
            ->selectRaw('latest.pending as snapshot_pending')
            ->selectRaw('latest.max_wait_seconds as snapshot_max_wait_seconds')
            ->selectRaw('latest.jobs_per_minute as snapshot_jobs_per_minute')
            ->selectRaw('latest.failed_in_window as snapshot_failed_in_window')
            ->selectRaw('latest.failed_window_minutes as snapshot_failed_window_minutes')
            ->selectRaw('latest.failed_last_hour as snapshot_failed_last_hour')
            ->selectRaw('latest.workers as snapshot_workers')
            ->selectRaw('latest.node_count as snapshot_node_count')
            ->leftJoinLateral($snapshot, 'latest')
            ->whereIn('environment_states.environment_id', array_keys($latest))
            ->get();

        foreach ($states as $state) {
            $latest[$state->environment_id] = $state;
        }

        return $latest;
    }

    public function isStale(Environment $environment, ?EnvironmentState $state, CarbonImmutable $now): bool
    {
        $since = $state !== null ? $state->captured_at : $environment->created_at?->toImmutable();

        if ($since === null) {
            return true;
        }

        $seconds = config()->integer('horizon-watch.stale_after_intervals') * $environment->poll_interval_seconds;

        return $since->lt($now->subSeconds($seconds));
    }

    /**
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    public function throughputSeries(array $environmentIds, SeriesRange $range): array
    {
        return $this->series($environmentIds, $range, SeriesAggregate::Throughput);
    }

    /**
     * @return array<int, int>
     */
    public function maxWaitSeries(int $environmentId, SeriesRange $range): array
    {
        return $this->series([$environmentId], $range, SeriesAggregate::MaxWait);
    }

    /**
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    private function series(array $environmentIds, SeriesRange $range, SeriesAggregate $aggregate): array
    {
        $points = self::seriesPoints();
        $tick = config()->integer('horizon-watch.readings.poll_tick_seconds');
        $slack = config()->integer('horizon-watch.readings.poll_slack_seconds');
        $values = array_fill(0, $points, 0.0);

        if ($environmentIds === []) {
            return array_map(fn () => 0, $values);
        }

        [$from, $until, $step] = $this->grid($range);
        $placeholders = implode(', ', array_fill(0, count($environmentIds), '?'));

        $rows = DB::select(
            <<<SQL
                select s.environment_id,
                       e.poll_interval_seconds as poll_interval,
                       extract(epoch from date_bin(make_interval(secs => ?), s.captured_at, ?::timestamptz))::bigint as bucket,
                       {$aggregate->sql()} filter (where s.error is null) as value
                from environment_snapshots s
                join environments e on e.id = s.environment_id
                where s.environment_id in ({$placeholders})
                  and s.captured_at >= ?::timestamptz and s.captured_at < ?::timestamptz
                group by s.environment_id, e.poll_interval_seconds, bucket
                SQL,
            [$step, self::SERIES_ORIGIN, ...array_values($environmentIds), $from->toIso8601String(), $until->toIso8601String()],
        );

        $buckets = [];
        $reach = [];

        foreach ($rows as $row) {
            $index = intdiv((int) $row->bucket - $from->getTimestamp(), $step);

            if ($index >= 0 && $index < $points) {
                $buckets[(int) $row->environment_id][$index] = $row->value === null ? null : (float) $row->value;
                $gap = (int) ceil((int) $row->poll_interval / $tick) * $tick + $slack;
                $reach[(int) $row->environment_id] = (int) ceil($gap / $step) - 1;
            }
        }

        foreach ($buckets as $environmentId => $own) {
            $last = null;
            $lastIndex = 0;

            for ($index = 0; $index < $points; $index++) {
                if (array_key_exists($index, $own)) {
                    [$last, $lastIndex] = [$own[$index], $index];
                    $values[$index] += $last ?? 0.0;
                } elseif ($last !== null && $index - $lastIndex <= $reach[$environmentId]) {
                    $values[$index] += $last;
                }
            }
        }

        return array_map(fn (float $value) => (int) round($value), $values);
    }

    /**
     * @param  iterable<Environment>  $environments
     * @return array<int, array{points: list<int>, percent: int|null}>
     */
    public function pendingTrends(iterable $environments): array
    {
        $averages = [];

        foreach ($environments as $environment) {
            $averages[$environment->id] = [];
        }

        if ($averages === []) {
            return [];
        }

        $step = config()->integer('horizon-watch.readings.trend_step_seconds');
        $points = self::trendPoints();
        [$from, $until] = $this->window($step, $points);
        $placeholders = implode(', ', array_fill(0, count($averages), '?'));

        $rows = DB::select(
            <<<SQL
                select environment_id,
                       extract(epoch from date_bin(make_interval(secs => ?), captured_at, ?::timestamptz))::bigint as bucket,
                       avg(pending) as value
                from environment_snapshots
                where environment_id in ({$placeholders})
                  and captured_at >= ?::timestamptz and captured_at < ?::timestamptz
                  and error is null
                group by environment_id, bucket
                SQL,
            [$step, self::SERIES_ORIGIN, ...array_keys($averages), $from->toIso8601String(), $until->toIso8601String()],
        );

        foreach ($rows as $row) {
            $index = intdiv((int) $row->bucket - $from->getTimestamp(), $step);

            if ($index >= 0 && $index < $points) {
                $averages[(int) $row->environment_id][$index] = (float) $row->value;
            }
        }

        return array_map(function (array $buckets) use ($points): array {
            ksort($buckets);

            $values = array_fill(0, $points, 0);

            foreach ($buckets as $index => $value) {
                $values[$index] = (int) round($value);
            }

            return ['points' => array_values($values), 'percent' => $this->variation(array_values($buckets))];
        }, $averages);
    }

    /**
     * @param  list<float>  $buckets
     */
    private function variation(array $buckets): ?int
    {
        $span = config()->integer('horizon-watch.readings.trend_span');

        if ($span < 1 || count($buckets) < 2 * $span) {
            return null;
        }

        $recent = array_sum(array_slice($buckets, -$span)) / $span;
        $base = array_sum(array_slice($buckets, -2 * $span, $span)) / $span;

        if ($base == 0.0) {
            return null;
        }

        return (int) round(($recent - $base) / $base * 100);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    public function grid(SeriesRange $range): array
    {
        $points = self::seriesPoints();
        $step = intdiv(match ($range) {
            SeriesRange::ThreeHours => 3 * 3600,
            SeriesRange::Day => 24 * 3600,
            SeriesRange::Week => 7 * 24 * 3600,
        }, $points);

        return [...$this->window($step, $points), $step];
    }

    public static function seriesPoints(): int
    {
        return config()->integer('horizon-watch.readings.series_points');
    }

    public static function trendPoints(): int
    {
        return config()->integer('horizon-watch.readings.trend_points');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(int $step, int $points): array
    {
        $last = intdiv(Date::now()->getTimestamp(), $step) * $step;
        $from = CarbonImmutable::createFromTimestampUTC($last - ($points - 1) * $step);

        return [$from, $from->addSeconds($points * $step)];
    }
}
