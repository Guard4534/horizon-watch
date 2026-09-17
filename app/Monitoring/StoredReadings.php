<?php

namespace App\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\SeriesRange;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class StoredReadings
{
    public const SERIES_POINTS = 48;

    public const TREND_POINTS = 12;

    private const TREND_STEP_SECONDS = 300;

    private const TREND_SPAN = 3;

    public const LOOKBACK_HOURS = 24;

    private const POLL_TICK_SECONDS = 15;

    private const POLL_SLACK_SECONDS = 15;

    private const STATUS_METRICS = [
        'unreachable' => AlertRuleMetric::EndpointUnreachable,
        'inactive' => AlertRuleMetric::HorizonMasterInactive,
        'paused' => AlertRuleMetric::HorizonPaused,
    ];

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

        $seconds = (int) config('horizon-watch.stale_after_intervals') * $environment->poll_interval_seconds;

        return $since->lt($now->subSeconds($seconds));
    }

    /**
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    public function throughputSeries(array $environmentIds, SeriesRange $range): array
    {
        return $this->series($environmentIds, $range, 'avg(s.jobs_per_minute)');
    }

    /**
     * @return array<int, int>
     */
    public function maxWaitSeries(int $environmentId, SeriesRange $range): array
    {
        return $this->series([$environmentId], $range, 'max(s.max_wait_seconds)');
    }

    /**
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    private function series(array $environmentIds, SeriesRange $range, string $aggregate): array
    {
        $values = array_fill(0, self::SERIES_POINTS, 0.0);

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
                       {$aggregate} filter (where s.error is null) as value
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

            if ($index >= 0 && $index < self::SERIES_POINTS) {
                $buckets[(int) $row->environment_id][$index] = $row->value === null ? null : (float) $row->value;
                $gap = (int) ceil((int) $row->poll_interval / self::POLL_TICK_SECONDS) * self::POLL_TICK_SECONDS + self::POLL_SLACK_SECONDS;
                $reach[(int) $row->environment_id] = (int) ceil($gap / $step) - 1;
            }
        }

        foreach ($buckets as $environmentId => $own) {
            $last = null;
            $lastIndex = 0;

            for ($index = 0; $index < self::SERIES_POINTS; $index++) {
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

        [$from, $until] = $this->window(self::TREND_STEP_SECONDS, self::TREND_POINTS);
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
            [self::TREND_STEP_SECONDS, self::SERIES_ORIGIN, ...array_keys($averages), $from->toIso8601String(), $until->toIso8601String()],
        );

        foreach ($rows as $row) {
            $index = intdiv((int) $row->bucket - $from->getTimestamp(), self::TREND_STEP_SECONDS);

            if ($index >= 0 && $index < self::TREND_POINTS) {
                $averages[(int) $row->environment_id][$index] = (float) $row->value;
            }
        }

        return array_map(function (array $buckets): array {
            ksort($buckets);

            $points = array_fill(0, self::TREND_POINTS, 0);

            foreach ($buckets as $index => $value) {
                $points[$index] = (int) round($value);
            }

            return ['points' => $points, 'percent' => $this->variation(array_values($buckets))];
        }, $averages);
    }

    /**
     * @param  list<float>  $buckets
     */
    private function variation(array $buckets): ?int
    {
        if (count($buckets) < 2 * self::TREND_SPAN) {
            return null;
        }

        $recent = array_sum(array_slice($buckets, -self::TREND_SPAN)) / self::TREND_SPAN;
        $base = array_sum(array_slice($buckets, -2 * self::TREND_SPAN, self::TREND_SPAN)) / self::TREND_SPAN;

        if ($base == 0.0) {
            return null;
        }

        return (int) round(($recent - $base) / $base * 100);
    }

    /**
     * @param  array<int, Environment>  $environments
     * @return array<int, list<array{metric: AlertRuleMetric, since: CarbonImmutable, truncated: bool}>>
     */
    public function openAnomalies(array $environments): array
    {
        $ids = array_values(array_unique(array_map(fn (Environment $environment) => $environment->id, $environments)));

        if ($ids === []) {
            return [];
        }

        $carries = fn (string $row) => $this->carries($row);
        $fromStatus = $this->metricOfStatus('latest.status');
        $statuses = $this->quotedList(array_keys(self::STATUS_METRICS));
        $lookback = self::LOOKBACK_HOURS;

        $rows = DB::select(
            <<<SQL
                with latest as (
                    select s.environment_id, s.id, s.captured_at, s.status, s.breaches
                    from unnest(?::bigint[]) as e(id)
                    cross join lateral (
                        select environment_id, id, captured_at, status, breaches
                        from environment_snapshots
                        where environment_id = e.id
                          and (environment_id, captured_at) <= (e.id, 'infinity'::timestamptz)
                        order by environment_id desc, captured_at desc, id desc
                        limit 1
                    ) s
                ), anomaly as (
                    select latest.environment_id, latest.id, latest.captured_at, breach.metric
                    from latest, jsonb_array_elements_text(latest.breaches::jsonb) as breach(metric)
                    union
                    select latest.environment_id, latest.id, latest.captured_at, {$fromStatus}
                    from latest
                    where latest.status in ({$statuses})
                )
                select anomaly.environment_id,
                       anomaly.metric,
                       coalesce(run.captured_at, anomaly.captured_at) as since,
                       (gap.id is null and coalesce(older.carries, false)) as truncated
                from anomaly
                left join lateral (
                    select gap.id, gap.captured_at
                    from environment_snapshots gap
                    where gap.environment_id = anomaly.environment_id
                      and (gap.environment_id, gap.captured_at) <= (anomaly.environment_id, anomaly.captured_at)
                      and gap.captured_at >= anomaly.captured_at - interval '{$lookback} hours'
                      and (gap.captured_at < anomaly.captured_at or gap.id < anomaly.id)
                      and not {$carries('gap')}
                    order by gap.environment_id desc, gap.captured_at desc, gap.id desc
                    limit 1
                ) gap on true
                left join lateral (
                    select run.captured_at
                    from environment_snapshots run
                    where run.environment_id = anomaly.environment_id
                      and run.captured_at >= coalesce(gap.captured_at, anomaly.captured_at - interval '{$lookback} hours')
                      and (gap.id is null or run.captured_at > gap.captured_at or run.id > gap.id)
                    order by run.environment_id, run.captured_at, run.id
                    limit 1
                ) run on true
                left join lateral (
                    select {$carries('older')} as carries
                    from environment_snapshots older
                    where older.environment_id = anomaly.environment_id
                      and (older.environment_id, older.captured_at)
                          < (anomaly.environment_id, anomaly.captured_at - interval '{$lookback} hours')
                    order by older.environment_id desc, older.captured_at desc, older.id desc
                    limit 1
                ) older on gap.id is null
                SQL,
            ['{'.implode(',', $ids).'}'],
        );

        $anomalies = [];
        $now = Date::now()->getTimestamp();

        foreach ($rows as $row) {
            $metric = AlertRuleMetric::tryFrom($row->metric);

            if ($metric === null) {
                continue;
            }

            $since = Date::parse($row->since)->toImmutable();
            $truncated = (bool) $row->truncated;

            if (! $truncated
                && in_array($metric, self::STATUS_METRICS, true)
                && $now - $since->getTimestamp() < $metric->defaultThreshold() * 60) {
                continue;
            }

            $anomalies[(int) $row->environment_id][] = [
                'metric' => $metric,
                'since' => $since,
                'truncated' => $truncated,
            ];
        }

        $order = array_flip(array_map(fn (AlertRuleMetric $metric) => $metric->value, AlertRuleMetric::cases()));

        $rank = fn (AlertRuleMetric $metric) => [$metric->defaultSeverity() === AlertSeverity::Critical ? 0 : 1, $order[$metric->value]];

        return array_map(function (array $list) use ($rank) {
            usort($list, fn (array $a, array $b) => $rank($a['metric']) <=> $rank($b['metric']));

            return $list;
        }, $anomalies);
    }

    private function carries(string $row): string
    {
        $arms = '';

        foreach (self::STATUS_METRICS as $status => $metric) {
            $arms .= " when '{$metric->value}' then {$row}.status = '{$status}'";
        }

        return "(case anomaly.metric{$arms} else strpos({$row}.breaches::text, '\"' || anomaly.metric || '\"') > 0 end)";
    }

    private function metricOfStatus(string $column): string
    {
        $arms = '';

        foreach (self::STATUS_METRICS as $status => $metric) {
            $arms .= " when '{$status}' then '{$metric->value}'";
        }

        return "(case {$column}{$arms} end)";
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    private function grid(SeriesRange $range): array
    {
        $step = intdiv(match ($range) {
            SeriesRange::ThreeHours => 3 * 3600,
            SeriesRange::Day => 24 * 3600,
            SeriesRange::Week => 7 * 24 * 3600,
        }, self::SERIES_POINTS);

        return [...$this->window($step, self::SERIES_POINTS), $step];
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
