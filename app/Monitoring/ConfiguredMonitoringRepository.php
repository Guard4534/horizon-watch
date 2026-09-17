<?php

namespace App\Monitoring;

use App\Data\Applications\EnvironmentFormData;
use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\NotificationSettingsData;
use App\Data\Monitoring\QueueData;
use App\Data\Monitoring\RuleScopeData;
use App\Data\Monitoring\SentNotificationData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\EnvironmentStatus;
use App\Enums\NotificationChannel;
use App\Enums\RuleOrigin;
use App\Enums\SentNotificationKind;
use App\Enums\SeriesRange;
use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentState;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

class ConfiguredMonitoringRepository implements MonitoringRepository
{
    private const OVERRIDES = [
        'production' => ['horizon.master_inactive' => 2, 'queue.pending' => 5000, 'queue.max_wait' => 30],
        'preprod' => ['horizon.master_inactive' => 10],
        'worker-batch' => ['job.runtime' => 900, 'workers.missing' => 2],
    ];

    /**
     * @var array<int, EloquentCollection<int, Environment>>
     */
    private array $visibleEnvironmentsByTeam = [];

    /**
     * @var array<int, Collection<string, Environment>>
     */
    private array $visibleEnvironmentsBySlugByTeam = [];

    /**
     * @var array<int, EloquentCollection<int, Environment>>
     */
    private array $configurableEnvironmentsByTeam = [];

    /**
     * @var array<int, EloquentCollection<int, Application>>
     */
    private array $configurableApplicationsByTeam = [];

    /**
     * @var array<int, EloquentCollection<int, Application>>
     */
    private array $emptyApplicationsByTeam = [];

    /**
     * @var array<int, EnvironmentState|null>
     */
    private array $statesByEnvironment = [];

    /**
     * @var array<int, array{points: list<int>, percent: int|null}>
     */
    private array $trendsByEnvironment = [];

    /**
     * @var array<int, array<int, list<array{metric: AlertRuleMetric, since: CarbonImmutable, truncated: bool}>>>
     */
    private array $openAnomaliesByTeam = [];

    /**
     * @var array<int, bool>
     */
    private array $managesApplicationsByTeam = [];

    public function __construct(
        private readonly Guard $auth,
        private readonly VisibleEnvironments $visible,
        private readonly StoredReadings $readings,
    ) {}

    public function applications(Team $team): array
    {
        return $this->visibleApplications($team)
            ->map($this->toApplicationData(...))
            ->all();
    }

    public function environments(Team $team): array
    {
        $environments = $this->visibleEnvironmentModels($team);
        $this->loadStates($environments);

        return $environments
            ->map(fn (Environment $environment) => $this->toEnvironmentData($environment, watched: true))
            ->all();
    }

    public function configurableApplications(Team $team): array
    {
        return $this->configurableApplicationModels($team)
            ->map($this->toApplicationData(...))
            ->all();
    }

    public function configurableApplication(Team $team, string $applicationId): ?ApplicationData
    {
        $application = $this->configurableApplicationModels($team)->firstWhere('slug', $applicationId);

        return $application ? $this->toApplicationData($application) : null;
    }

    public function configurableEnvironments(Team $team): array
    {
        $watched = $this->watchedEnvironmentsBySlug($team);
        $environments = $this->configurableEnvironmentModels($team);
        $this->loadStates($environments);

        return $environments
            ->map(fn (Environment $environment) => $this->toEnvironmentData(
                $environment,
                watched: $watched->has($environment->slug),
            ))
            ->all();
    }

    public function environment(Team $team, string $environmentId): ?EnvironmentData
    {
        $environment = $this->findEnvironment($team, $environmentId);

        if ($environment === null) {
            return null;
        }

        $this->stateOf($team, $environment);

        return $this->toEnvironmentData($environment, watched: true);
    }

    public function nodes(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);
        $state = $environment ? $this->stateOf($team, $environment) : null;

        if ($state === null) {
            return [];
        }

        $now = $this->now();

        return array_map(fn (array $node) => new NodeData(
            hostname: $node['hostname'],
            status: match (true) {
                $state->status->isDown() => $state->status,
                $node['status'] === 'paused' => EnvironmentStatus::Paused,
                default => EnvironmentStatus::Active,
            },
            workers: $node['workers'],
            supervisorCount: $node['supervisors'],
            queueCount: $node['queues'],
            seenSecondsAgo: max(0, (int) (isset($node['seenAt']) ? CarbonImmutable::parse($node['seenAt']) : $state->captured_at)->diffInSeconds($now, false)),
        ), $state->nodes);
    }

    public function queues(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);
        $state = $environment ? $this->stateOf($team, $environment) : null;

        if ($state === null) {
            return [];
        }

        return array_map(fn (array $queue) => new QueueData(
            name: $queue['name'],
            supervisor: $queue['supervisor'],
            workers: $queue['workers'],
            pending: $queue['pending'],
            waitSeconds: $queue['waitSeconds'],
            runtimeSeconds: $queue['runtimeSeconds'] === null ? null : (float) $queue['runtimeSeconds'],
            status: $this->queueStatus($state, $queue),
        ), $state->queues);
    }

    public function failedJobs(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);
        $state = $environment ? $this->stateOf($team, $environment) : null;

        if ($state === null) {
            return [];
        }

        $now = $this->now();

        return array_map(fn (array $job) => new FailedJobData(
            job: $job['job'],
            queue: $job['queue'],
            exception: $job['exception'],
            tries: $job['tries'],
            minutesAgo: max(0, (int) CarbonImmutable::parse($job['failedAt'])->diffInMinutes($now)),
        ), $state->failed_jobs);
    }

    public function longRunningJobs(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);
        $state = $environment ? $this->stateOf($team, $environment) : null;

        if ($state === null) {
            return [];
        }

        $now = $this->now();
        $threshold = AlertRuleMetric::JobRuntime->defaultThreshold();
        $jobs = [];

        foreach ($state->pending_jobs as $job) {
            $reservedAt = CarbonImmutable::parse($job['reservedAt']);
            $elapsed = (int) $reservedAt->diffInSeconds($now);

            if ($elapsed <= $threshold) {
                continue;
            }

            $jobs[] = new LongRunningJobData(
                job: $job['job'],
                queue: $job['queue'],
                elapsedSeconds: $elapsed,
                startedAt: $reservedAt->setTimezone((string) config('app.timezone'))->format('H:i'),
            );
        }

        usort($jobs, fn (LongRunningJobData $a, LongRunningJobData $b) => $b->elapsedSeconds <=> $a->elapsedSeconds);

        return $jobs;
    }

    public function throughputSeries(Team $team, ?string $environmentId, SeriesRange $range): array
    {
        if ($environmentId === null) {
            return $this->readings->throughputSeries(
                $this->visibleEnvironmentModels($team)->modelKeys(),
                $range,
            );
        }

        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->readings->throughputSeries([$environment->id], $range) : [];
    }

    public function maxWaitSeries(Team $team, string $environmentId, SeriesRange $range): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->readings->maxWaitSeries($environment->id, $range) : [];
    }

    public function alerts(Team $team, AlertState $state): array
    {
        if ($state !== AlertState::Open) {
            return [];
        }

        $environments = $this->environments($team);
        usort($environments, EnvironmentData::compareBySeverityThenPending(...));

        $models = $this->watchedEnvironmentsBySlug($team);
        $anomalies = $this->openAnomaliesByTeam[$team->id]
            ??= $this->readings->openAnomalies($models->values()->all());
        $now = $this->now();
        $cap = StoredReadings::LOOKBACK_HOURS * 60;
        $alerts = [];

        foreach ($environments as $environment) {
            $model = $models->get($environment->id);

            if ($model === null || $environment->status === null) {
                continue;
            }

            foreach ($anomalies[$model->id] ?? [] as $anomaly) {
                $minutes = $anomaly['truncated'] ? $cap : min($cap, max(0, (int) $anomaly['since']->diffInMinutes($now)));

                $alerts[] = $this->makeAlert(
                    $environment,
                    $environment->status,
                    $anomaly['metric'],
                    $minutes,
                    $anomaly['truncated'] || $minutes >= $cap,
                );
            }
        }

        return $alerts;
    }

    public function sentNotifications(Team $team): array
    {
        $environments = $this->environments($team);

        if ($environments === []) {
            return [];
        }

        usort($environments, EnvironmentData::compareBySeverityThenPending(...));

        $subject = fn (EnvironmentData $environment) => "{$environment->applicationName} · {$environment->name}";
        $worst = $environments[0];
        $best = $environments[count($environments) - 1];

        return [
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::CriticalAlert, $subject($worst), 4),
            new SentNotificationData(NotificationChannel::Webhook, SentNotificationKind::WebhookDelivery, 'hooks.example.com/horizon · 200', 4),
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::WarningDigest, (string) min(3, count($environments)), 18),
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::Resolved, $subject($best), 52),
        ];
    }

    public function ruleScopes(Team $team): array
    {
        $environments = $this->environments($team);

        $scopes = [new RuleScopeData(
            id: 'organization',
            color: null,
            environmentCount: count($environments),
            overrideCount: 0,
        )];

        $names = [];

        foreach ($environments as $environment) {
            if (isset($names[$environment->name])) {
                continue;
            }

            $names[$environment->name] = true;

            $scopes[] = new RuleScopeData(
                id: $environment->name,
                color: $environment->color,
                environmentCount: count(array_filter($environments, fn (EnvironmentData $item) => $item->name === $environment->name)),
                overrideCount: count(self::OVERRIDES[$environment->name] ?? []),
            );
        }

        return $scopes;
    }

    public function alertRules(Team $team, string $scope): array
    {
        $scopeIds = array_map(fn (RuleScopeData $item) => $item->id, $this->ruleScopes($team));

        if (! in_array($scope, $scopeIds, true)) {
            return [];
        }

        $overrides = self::OVERRIDES[$scope] ?? [];

        return array_map(fn (AlertRuleMetric $metric) => new AlertRuleData(
            metric: $metric,
            threshold: (float) ($overrides[$metric->value] ?? $metric->defaultThreshold()),
            unit: $metric->unit(),
            severity: $metric->defaultSeverity(),
            notifyByEmail: $metric->notifiesByEmailByDefault(),
            origin: isset($overrides[$metric->value]) ? RuleOrigin::Override : RuleOrigin::Organization,
        ), AlertRuleMetric::cases());
    }

    public function notificationSettings(Team $team): NotificationSettingsData
    {
        return new NotificationSettingsData(
            recipients: ['ops@example.com', 'oncall@example.com'],
            webhookUrl: 'https://hooks.example.com/horizon',
            quietFrom: '23:00',
            quietTo: '07:00',
            repeatMinutes: 30,
        );
    }

    /**
     * @return EloquentCollection<int, Environment>
     */
    private function visibleEnvironmentModels(Team $team): EloquentCollection
    {
        return $this->visibleEnvironmentsByTeam[$team->id] ??= $this->visible->query($team, $this->currentUser())->get();
    }

    /**
     * @return Collection<int, Application>
     */
    private function visibleApplications(Team $team): Collection
    {
        $applications = $this->visibleEnvironmentModels($team)
            ->pluck('application')
            ->unique('id');

        if ($this->managesApplications($team)) {
            $applications = $applications->concat($this->emptyApplications($team))->unique('id');
        }

        return $applications->sortBy('id')->values();
    }

    /**
     * @return EloquentCollection<int, Application>
     */
    private function emptyApplications(Team $team): EloquentCollection
    {
        return $this->emptyApplicationsByTeam[$team->id] ??= $team->applications()->doesntHave('environments')->get();
    }

    /**
     * @return Collection<int, Application>
     */
    private function configurableApplicationModels(Team $team): Collection
    {
        if (! $this->managesApplications($team)) {
            return $this->visibleApplications($team);
        }

        return $this->configurableApplicationsByTeam[$team->id] ??= $team->applications()->orderBy('id')->get();
    }

    /**
     * @return EloquentCollection<int, Environment>
     */
    private function configurableEnvironmentModels(Team $team): EloquentCollection
    {
        if (! $this->managesApplications($team)) {
            return $this->visibleEnvironmentModels($team);
        }

        return $this->configurableEnvironmentsByTeam[$team->id] ??= $this->visible->ofTeam($team)->get();
    }

    private function managesApplications(Team $team): bool
    {
        return $this->managesApplicationsByTeam[$team->id]
            ??= $this->currentUser()->hasTeamPermission($team, TeamPermission::ManageApplications);
    }

    private function findEnvironment(Team $team, string $environmentId): ?Environment
    {
        return $this->watchedEnvironmentsBySlug($team)->get($environmentId);
    }

    /**
     * @return Collection<string, Environment>
     */
    private function watchedEnvironmentsBySlug(Team $team): Collection
    {
        return $this->visibleEnvironmentsBySlugByTeam[$team->id]
            ??= $this->visibleEnvironmentModels($team)->keyBy('slug');
    }

    /**
     * @param  iterable<Environment>  $environments
     */
    private function loadStates(iterable $environments): void
    {
        $missing = [];

        foreach ($environments as $environment) {
            if (! array_key_exists($environment->id, $this->statesByEnvironment)) {
                $missing[] = $environment;
            }
        }

        if ($missing !== []) {
            $this->statesByEnvironment += $this->readings->latestFor($missing);
            $this->trendsByEnvironment += $this->readings->pendingTrends($missing);
        }
    }

    private function stateOf(Team $team, Environment $environment): ?EnvironmentState
    {
        if (! array_key_exists($environment->id, $this->statesByEnvironment)) {
            $this->loadStates($this->visibleEnvironmentModels($team));
        }

        return $this->statesByEnvironment[$environment->id] ?? null;
    }

    /**
     * @param  array{name: string, supervisor: string|null, workers: int, pending: int, waitSeconds: int, runtimeSeconds: float|null}  $queue
     */
    private function queueStatus(EnvironmentState $state, array $queue): EnvironmentStatus
    {
        return match (true) {
            $state->status->isDown(), $state->status === EnvironmentStatus::Paused => $state->status,
            $queue['waitSeconds'] > AlertRuleMetric::QueueMaxWait->defaultThreshold(),
            $queue['pending'] > AlertRuleMetric::QueuePending->defaultThreshold(),
            $queue['workers'] === 0 && $queue['pending'] > 0 => EnvironmentStatus::Degraded,
            default => EnvironmentStatus::Active,
        };
    }

    private function now(): CarbonImmutable
    {
        return Date::now()->toImmutable();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->auth->user();

        return $user;
    }

    private function toApplicationData(Application $application): ApplicationData
    {
        return new ApplicationData($application->slug, $application->name, $application->host);
    }

    private function toEnvironmentData(Environment $environment, bool $watched): EnvironmentData
    {
        $state = $watched ? $this->statesByEnvironment[$environment->id] ?? null : null;
        $trend = $watched ? $this->trendsByEnvironment[$environment->id] ?? null : null;
        $stale = $watched
            && $environment->polling_enabled
            && $this->readings->isStale($environment, $state, $this->now());

        return new EnvironmentData(
            id: $environment->slug,
            applicationId: $environment->application->slug,
            applicationName: $environment->application->name,
            name: $environment->name,
            color: $environment->color,
            horizonUrl: EnvironmentFormData::withoutUserinfo($environment->horizon_url),
            status: match (true) {
                ! $watched => null,
                $state !== null => $state->status,
                $stale => EnvironmentStatus::Unreachable,
                default => null,
            },
            pending: $this->snapshotNumber($state, 'pending'),
            trend: $watched ? $trend['points'] ?? array_fill(0, StoredReadings::TREND_POINTS, 0) : [],
            trendPercent: $trend['percent'] ?? null,
            maxWaitSeconds: $this->snapshotNumber($state, 'max_wait_seconds'),
            failedInWindow: $this->snapshotNumber($state, 'failed_in_window'),
            failedWindowMinutes: $this->snapshotNumber($state, 'failed_window_minutes') ?: 10080,
            failedLastHour: $this->snapshotNumber($state, 'failed_last_hour'),
            workers: $this->snapshotNumber($state, 'workers'),
            jobsPerMinute: $this->snapshotNumber($state, 'jobs_per_minute'),
            nodeCount: $this->snapshotNumber($state, 'node_count'),
            basicAuthUser: $environment->basic_auth_user,
            latencyMs: $state?->latency_ms,
            watched: $watched,
            lastReadingAt: $state?->captured_at->toIso8601String(),
            stale: $stale,
            pollingEnabled: $environment->polling_enabled,
            pollIntervalSeconds: $environment->poll_interval_seconds,
            readingError: $state?->error,
            horizonStatus: $state?->horizon_status,
        );
    }

    private function snapshotNumber(?EnvironmentState $state, string $column): int
    {
        return (int) $state?->getAttribute("snapshot_{$column}");
    }

    private function makeAlert(EnvironmentData $environment, EnvironmentStatus $status, AlertRuleMetric $metric, int $minutesAgo, bool $sinceTruncated): AlertData
    {
        return new AlertData(
            id: "{$environment->id}:{$metric->value}",
            state: AlertState::Open,
            severity: $metric->defaultSeverity(),
            metric: $metric,
            threshold: $metric->defaultThreshold(),
            unit: $metric->unit(),
            environmentId: $environment->id,
            applicationName: $environment->applicationName,
            environmentName: $environment->name,
            color: $environment->color,
            environmentStatus: $status,
            nodeCount: $environment->nodeCount,
            pending: $environment->pending,
            maxWaitSeconds: $environment->maxWaitSeconds,
            minutesAgo: $minutesAgo,
            sinceTruncated: $sinceTruncated,
        );
    }
}
