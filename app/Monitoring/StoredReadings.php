<?php

namespace App\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentStatus;
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
     * instead would scan the whole outage on every request.
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
        // eager-load trap in the project notes.
        $snapshot = EnvironmentSnapshot::query()
            ->select(['pending', 'max_wait_seconds', 'jobs_per_minute', 'failed_last_24_hours', 'failed_window_minutes', 'workers', 'node_count'])
            ->whereColumn('environment_snapshots.environment_id', 'environment_states.environment_id')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
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
     * Whether the environment has gone too long without a reading. Pausing
     * the collection is the caller's business: this only measures time.
     */
    public function isStale(Environment $environment, ?EnvironmentState $state, CarbonImmutable $now): bool
    {
        if ($state === null) {
            return true;
        }

        $seconds = (int) config('horizon-watch.stale_after_intervals') * $environment->poll_interval_seconds;

        return $state->captured_at->lt($now->subSeconds($seconds));
    }

    /**
     * Jobs per minute, one point per bucket, summed across the given
     * environments: each environment's average over the bucket, then the
     * sum. Failed readings measured nothing and are left out, so a bucket
     * holding only those reads 0, like a bucket without readings.
     *
     * @param  array<int, int>  $environmentIds
     * @return array<int, int>
     */
    public function throughputSeries(array $environmentIds, SeriesRange $range): array
    {
        if ($environmentIds === []) {
            return array_fill(0, self::SERIES_POINTS, 0);
        }

        [$from, $until, $step] = $this->grid($range);

        $placeholders = implode(', ', array_fill(0, count($environmentIds), '?'));

        $rows = DB::select(
            <<<SQL
                select bucket, sum(average) as value
                from (
                    select environment_id,
                           extract(epoch from date_bin(make_interval(secs => ?), captured_at, ?::timestamptz))::bigint as bucket,
                           avg(jobs_per_minute) as average
                    from environment_snapshots
                    where environment_id in ({$placeholders})
                      and captured_at >= ?::timestamptz and captured_at < ?::timestamptz
                      and error is null
                    group by environment_id, bucket
                ) per_environment
                group by bucket
                SQL,
            [$step, self::SERIES_ORIGIN, ...array_values($environmentIds), $from->toIso8601String(), $until->toIso8601String()],
        );

        return $this->fill($rows, $from, $step);
    }

    /**
     * The worst wait of each bucket for one environment.
     *
     * @return array<int, int>
     */
    public function maxWaitSeries(int $environmentId, SeriesRange $range): array
    {
        [$from, $until, $step] = $this->grid($range);

        $rows = DB::select(
            <<<'SQL'
                select extract(epoch from date_bin(make_interval(secs => ?), captured_at, ?::timestamptz))::bigint as bucket,
                       max(max_wait_seconds) as value
                from environment_snapshots
                where environment_id = ?
                  and captured_at >= ?::timestamptz and captured_at < ?::timestamptz
                  and error is null
                group by bucket
                SQL,
            [$step, self::SERIES_ORIGIN, $environmentId, $from->toIso8601String(), $until->toIso8601String()],
        );

        return $this->fill($rows, $from, $step);
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
     * carry them. "Down" anomalies follow the status (a reading that
     * failed, a Horizon without masters); the others follow the breach list.
     * A paused Horizon breaches nothing, so it opens no anomaly.
     *
     * The run is found by walking back from the latest snapshot to the last
     * one without the anomaly: a long outage costs a scan of the outage on
     * the (environment_id, captured_at) index.
     *
     * @param  array<int, Environment>  $environments
     * @return array<int, list<array{metric: AlertRuleMetric, since: CarbonImmutable}>>
     */
    public function openAnomalies(array $environments): array
    {
        $ids = array_values(array_unique(array_map(fn (Environment $environment) => $environment->id, $environments)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $unreachable = AlertRuleMetric::EndpointUnreachable->value;
        $inactive = AlertRuleMetric::HorizonMasterInactive->value;

        $rows = DB::select(
            <<<SQL
                with latest as (
                    select distinct on (environment_id) environment_id, captured_at, status, breaches
                    from environment_snapshots
                    where environment_id in ({$placeholders})
                    order by environment_id, captured_at desc, id desc
                ), anomaly as (
                    select environment_id, captured_at, metric
                    from latest, jsonb_array_elements_text(breaches::jsonb) as breach(metric)
                    union
                    select environment_id, captured_at, case status when ?::text then ?::text else ?::text end
                    from latest
                    where status in (?::text, ?::text)
                )
                select anomaly.environment_id, anomaly.metric, (
                    select min(run.captured_at)
                    from environment_snapshots run
                    where run.environment_id = anomaly.environment_id
                      and run.captured_at <= anomaly.captured_at
                      and run.captured_at > coalesce((
                          select max(gap.captured_at)
                          from environment_snapshots gap
                          where gap.environment_id = anomaly.environment_id
                            and gap.captured_at < anomaly.captured_at
                            and case anomaly.metric
                                when ?::text then gap.status <> ?::text
                                when ?::text then gap.status <> ?::text
                                else not (gap.breaches::jsonb @> jsonb_build_array(anomaly.metric))
                            end
                      ), '-infinity'::timestamptz)
                ) as since
                from anomaly
                SQL,
            [
                ...$ids,
                EnvironmentStatus::Unreachable->value, $unreachable, $inactive,
                EnvironmentStatus::Unreachable->value, EnvironmentStatus::Inactive->value,
                $unreachable, EnvironmentStatus::Unreachable->value,
                $inactive, EnvironmentStatus::Inactive->value,
            ],
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

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<int, int>
     */
    private function fill(array $rows, CarbonImmutable $from, int $step): array
    {
        $values = array_fill(0, self::SERIES_POINTS, 0);

        foreach ($rows as $row) {
            $index = intdiv((int) $row->bucket - $from->getTimestamp(), $step);

            if ($index >= 0 && $index < self::SERIES_POINTS) {
                $values[$index] = (int) round((float) $row->value);
            }
        }

        return $values;
    }
}
