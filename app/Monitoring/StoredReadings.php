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

/**
 * Reads what the poller stored. Every method receives environments the
 * repository has already filtered: there is no visibility rule here, and
 * there must never be one (VisibleEnvironments is its only home).
 */
class StoredReadings
{
    public const SERIES_POINTS = 48;

    public const TREND_POINTS = 12;

    private const TREND_STEP_SECONDS = 300;

    // The variation compares this many buckets with data to as many before them.
    private const TREND_SPAN = 3;

    public const LOOKBACK_HOURS = 24;

    // The anomalies a status stands for. Enum values only: they are
    // inlined in the anomaly query as literals.
    private const STATUS_METRICS = [
        'unreachable' => AlertRuleMetric::EndpointUnreachable,
        'inactive' => AlertRuleMetric::HorizonMasterInactive,
        'paused' => AlertRuleMetric::HorizonPaused,
    ];

    // date_bin() bins from this origin and PHP builds the same grid from the
    // Unix epoch: the two must agree, or every point lands one bucket off.
    private const SERIES_ORIGIN = '1970-01-01 00:00:00+00';

    /**
     * The latest state of each environment, with the numbers of its latest
     * snapshot attached as snapshot_* attributes, in one query.
     *
     * The numbers come from the latest snapshot whatever its outcome, so a
     * failed reading reads as zeros while the state keeps the detail of the
     * last successful one. Looking back for the last *successful* snapshot
     * instead would scan the whole outage on every request. A state whose
     * snapshots were all pruned (a collection paused for longer than the
     * retention) reads as zeros too.
     *
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

        // Correlated on columns, never on a model attribute: see the
        // eager-load trap in the project notes. The row comparison and the ordering
        // on (environment_id, captured_at) walk the composite index backwards
        // from the newest row; a plain "order by captured_at desc, id desc"
        // made PostgreSQL sort every row of the environment instead (336 ms
        // per environment whose latest reading is old, on 5 M rows).
        $snapshot = EnvironmentSnapshot::query()
            ->select(['pending', 'max_wait_seconds', 'jobs_per_minute', 'failed_last_24_hours', 'failed_window_minutes', 'workers', 'node_count'])
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
            ->selectRaw('latest.failed_last_24_hours as snapshot_failed_last_24_hours')
            ->selectRaw('latest.failed_window_minutes as snapshot_failed_window_minutes')
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

    /**
     * Whether the environment has gone too long without a reading: measured
     * from the latest reading, or from the environment's creation while it
     * has none (a new environment is waiting for its first poll, not
     * failing). Pausing the collection is the caller's business: this only
     * measures time.
     */
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
     * Jobs per minute, one point per bucket, summed across the given
     * environments: each environment's average over the bucket, then the
     * sum. Failed readings measured nothing and are left out.
     *
     * An environment polled less often than the bucket is wide (300 s
     * against the 3h chart's 225 s) leaves some of its buckets empty, and
     * drawing those as 0 saw-tooths the line. So each environment's last
     * value is carried into the empty buckets that follow it within the
     * window, but only as far as its interval explains the gap: a longer
     * gap (an outage, a pause, the time before its first reading) stays 0,
     * and a bucket nobody read stays 0.
     *
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    public function throughputSeries(array $environmentIds, SeriesRange $range): array
    {
        return $this->series($environmentIds, $range, 'avg(s.jobs_per_minute)');
    }

    /**
     * The worst wait of each bucket for one environment, with the same
     * carry-over as the throughput.
     *
     * @return array<int, int>
     */
    public function maxWaitSeries(int $environmentId, SeriesRange $range): array
    {
        return $this->series([$environmentId], $range, 'max(s.max_wait_seconds)');
    }

    /**
     * @param  array<int, int>  $environmentIds
     * @param  string  $aggregate  a literal aggregate over the alias "s", never input
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
                       {$aggregate} as value
                from environment_snapshots s
                join environments e on e.id = s.environment_id
                where s.environment_id in ({$placeholders})
                  and s.captured_at >= ?::timestamptz and s.captured_at < ?::timestamptz
                  and s.error is null
                group by s.environment_id, e.poll_interval_seconds, bucket
                SQL,
            [$step, self::SERIES_ORIGIN, ...array_values($environmentIds), $from->toIso8601String(), $until->toIso8601String()],
        );

        $buckets = [];
        $reach = [];

        foreach ($rows as $row) {
            $index = intdiv((int) $row->bucket - $from->getTimestamp(), $step);

            if ($index >= 0 && $index < self::SERIES_POINTS) {
                $buckets[(int) $row->environment_id][$index] = (float) $row->value;
                // Empty buckets an interval of this length can leave in a row.
                $reach[(int) $row->environment_id] = (int) ceil((int) $row->poll_interval / $step) - 1;
            }
        }

        foreach ($buckets as $environmentId => $own) {
            $last = null;
            $lastIndex = 0;

            for ($index = 0; $index < self::SERIES_POINTS; $index++) {
                if (array_key_exists($index, $own)) {
                    [$last, $lastIndex] = [$own[$index], $index];
                    $values[$index] += $last;
                } elseif ($last !== null && $index - $lastIndex <= $reach[$environmentId]) {
                    $values[$index] += $last;
                }
            }
        }

        return array_map(fn (float $value) => (int) round($value), $values);
    }

    /**
     * The pending trend of each environment over the last hour, in one
     * query: the average pending of each five-minute bucket, oldest first,
     * 0 where there is no reading, and the rounded variation between the
     * average of the last three buckets with data and of the three before
     * them (null with fewer than six such buckets, or a base of 0).
     *
     * Failed readings measured nothing and are left out, as in the series:
     * an outage must not read as the queue draining.
     *
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
     * The unrounded averages go in, so the percentage does not depend on
     * how the points were rounded for the chart.
     *
     * @param  list<float>  $buckets  the buckets with data, oldest first
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
     * The anomalies each environment's latest snapshot carries, worst
     * first, with the start of the uninterrupted run of snapshots that
     * carry them, in one statement. "Down" anomalies and the pause follow
     * the status (a reading that failed, a Horizon without masters, a
     * paused one); the others follow the breach list.
     *
     * Accepted, not accidental:
     * - one reading without the anomaly breaks the run, so a single
     *   unreachable blip in a long degradation restarts its "since";
     * - an environment whose collection is paused keeps the anomalies of
     *   its last reading (the page says the collection is paused beside
     *   them, and the demo relies on it).
     *
     * The run is looked for over at most LOOKBACK_HOURS before the latest
     * reading: a run that is longer comes back with "since" at the edge of
     * the look-back and "truncated" set. Walking an unbounded outage cost a
     * scan of the whole outage per anomaly per request. Persisted alerts
     * (phase 4) will carry their own start and make the cap moot.
     *
     * Every lookup goes through the composite (environment_id, captured_at)
     * index with a row comparison: without it PostgreSQL skip-scanned
     * nothing and picked the single-column captured_at index across every
     * environment (9 s on 5 M rows).
     *
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

        foreach ($rows as $row) {
            $metric = AlertRuleMetric::tryFrom($row->metric);

            if ($metric === null) {
                continue;
            }

            $anomalies[(int) $row->environment_id][] = [
                'metric' => $metric,
                'since' => Date::parse($row->since)->toImmutable(),
                'truncated' => (bool) $row->truncated,
            ];
        }

        $order = array_flip(array_map(fn (AlertRuleMetric $metric) => $metric->value, AlertRuleMetric::cases()));

        $rank = fn (AlertRuleMetric $metric) => [$metric->defaultSeverity() === AlertSeverity::Critical ? 0 : 1, $order[$metric->value]];

        return array_map(function (array $list) use ($rank) {
            usort($list, fn (array $a, array $b) => $rank($a['metric']) <=> $rank($b['metric']));

            return $list;
        }, $anomalies);
    }

    /**
     * Whether the snapshot aliased $row carries the anomaly of the
     * "anomaly" row: by status for the metrics a status stands for, by
     * breach list for the others.
     */
    private function carries(string $row): string
    {
        $arms = '';

        foreach (self::STATUS_METRICS as $status => $metric) {
            $arms .= " when '{$metric->value}' then {$row}.status = '{$status}'";
        }

        // A text search, not a JSON parse: the gap search reads up to a day
        // of rows per anomaly, and the column keeps the text the model
        // wrote. A metric value is a quoted, escape-free token, so its
        // quoted form only matches that element.
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
     * @param  list<string>  $values  enum values only, never input
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
    }

    /**
     * The bucket grid of a range: 48 buckets ending with the one that holds
     * "now", as the phase 1 charts drew them.
     *
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
     * The start and end of $points epoch-aligned buckets of $step seconds,
     * the last of which holds "now".
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(int $step, int $points): array
    {
        $last = intdiv(Date::now()->getTimestamp(), $step) * $step;
        $from = CarbonImmutable::createFromTimestampUTC($last - ($points - 1) * $step);

        return [$from, $from->addSeconds($points * $step)];
    }
}
