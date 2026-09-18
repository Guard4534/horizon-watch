<?php

namespace App\Monitoring;

use App\Alerts\EffectiveRules;
use App\Alerts\RuleSet;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Monitoring\AlertActorData;
use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertPageData;
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
use App\Data\Pages\AlertCountsData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\DeliveryStatus;
use App\Enums\EnvironmentStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Enums\SeriesRange;
use App\Enums\TeamPermission;
use App\Externals\Horizon\Data\HorizonStats;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentState;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class ConfiguredMonitoringRepository implements MonitoringRepository
{
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
     * @var array<int, array<int, AlertData>>
     */
    private array $openAlertsByTeam = [];

    /**
     * @var array<int, AlertCountsData>
     */
    private array $alertCountsByTeam = [];

    /**
     * @var array<int, array{mute: bool, handle: bool, all: bool}>
     */
    private array $alertAbilitiesByTeam = [];

    /**
     * @var array<int, bool>
     */
    private array $managesApplicationsByTeam = [];

    /**
     * @var array<int, NotificationSetting|false>
     */
    private array $notificationSettingsByTeam = [];

    public function __construct(
        private readonly Guard $auth,
        private readonly VisibleEnvironments $visible,
        private readonly StoredReadings $readings,
        private readonly EffectiveRules $effectiveRules,
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
        $this->loadStates($watched);

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

        $rules = $this->effectiveRules->forEnvironment($environment);

        return array_map(fn (array $queue) => new QueueData(
            name: $queue['name'],
            supervisor: $queue['supervisor'],
            workers: $queue['workers'],
            pending: $queue['pending'],
            waitSeconds: $queue['waitSeconds'],
            runtimeSeconds: $queue['runtimeSeconds'] === null ? null : (float) $queue['runtimeSeconds'],
            status: $this->queueStatus($state, $queue, $rules),
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

        $rule = $this->effectiveRules->forEnvironment($environment)->for(AlertRuleMetric::JobRuntime);

        if (! $rule->enabled) {
            return [];
        }

        $now = $this->now();
        $threshold = $rule->threshold;
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

    public function alerts(Team $team, AlertState $state, ?string $application = null, int $page = 1): AlertPageData
    {
        $page = max(1, $page);
        $perPage = config()->integer('horizon-watch.pages.alerts_per_page');
        $query = $this->alertsIn($team, $state, $application);
        $total = (clone $query)->count();

        return new AlertPageData(
            alerts: $total === 0 ? [] : $this->toAlertData($team, $query->forPage($page, $perPage)->get()),
            total: $total,
            perPage: $perPage,
            page: $page,
        );
    }

    public function openAlerts(Team $team): array
    {
        return $this->openAlertsByTeam[$team->id]
            ??= $this->toAlertData($team, $this->alertsIn($team, AlertState::Open)->get());
    }

    public function latestResolvedAlerts(Team $team, string $application, int $limit): array
    {
        return $this->toAlertData($team, $this->alertsIn($team, AlertState::Resolved, $application)->limit($limit)->get());
    }

    public function alertCounts(Team $team): AlertCountsData
    {
        return $this->alertCountsByTeam[$team->id] ??= $this->countAlerts($team);
    }

    private function countAlerts(Team $team): AlertCountsData
    {
        $now = $this->now()->utc();
        $muted = '(alerts.muted_indefinitely or coalesce(alerts.muted_until > ?, false))';

        $counts = $this->visibleAlerts($team, null, withDeleted: true)
            ->toBase()
            ->selectRaw(
                "count(*) filter (where alerts.resolved_at is null and alerts.environment_id is not null and not {$muted}) as open, "
                ."count(*) filter (where alerts.resolved_at is null and alerts.environment_id is not null and {$muted}) as muted, "
                .'count(*) filter (where alerts.resolved_at >= ?) as resolved',
                [$now, $now, $this->alertRetentionCutoff()],
            )
            ->first();

        return new AlertCountsData((int) $counts?->open, (int) $counts?->muted, (int) $counts?->resolved);
    }

    public function sentNotifications(Team $team): array
    {
        $user = $this->currentUser();
        $query = AlertNotification::query()
            ->where('team_id', $team->id)
            ->with('alert:id,application_name,environment_name')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(config()->integer('horizon-watch.pages.sent_notifications'));

        if (! $this->visible->seesEverything($team, $user)) {
            $query->whereHas('alert', fn (Builder $alerts) => $alerts
                ->whereIn('environment_id', $this->visibleEnvironmentModels($team)->modelKeys()));
        }

        $showsTarget = $user->hasTeamPermission($team, TeamPermission::ManageAlertRules);
        $now = $this->now();

        return $query->get()
            ->map(fn (AlertNotification $notification) => new SentNotificationData(
                channel: $notification->channel,
                kind: $notification->kind,
                status: $notification->status,
                subject: match (true) {
                    $notification->alert !== null => $notification->alert->application_name.' · '.$notification->alert->environment_name,
                    $notification->kind === SentNotificationKind::WarningDigest => (string) ($notification->environment_count ?? 0),
                    default => '',
                },
                target: $showsTarget ? $notification->target : null,
                minutesAgo: max(0, (int) $notification->sent_at->diffInMinutes($now)),
            ))
            ->all();
    }

    public function ruleScopes(Team $team): array
    {
        $environments = $this->environments($team);
        $overrideCounts = $this->effectiveRules->overrideCounts($team);

        $scopes = [new RuleScopeData(
            id: AlertRule::ORGANIZATION,
            color: null,
            environmentCount: count($environments),
            overrideCount: 0,
        )];

        $names = [];

        foreach ($environments as $environment) {
            $scope = Str::lower($environment->name);

            if (isset($names[$scope]) || $scope === AlertRule::ORGANIZATION) {
                continue;
            }

            $names[$scope] = true;

            $scopes[] = new RuleScopeData(
                id: $scope,
                color: $environment->color,
                environmentCount: count(array_filter($environments, fn (EnvironmentData $item) => Str::lower($item->name) === $scope)),
                overrideCount: $overrideCounts[$scope] ?? 0,
            );
        }

        if (! $this->alertAbilities($team)['all']) {
            return $scopes;
        }

        foreach ($overrideCounts as $scope => $count) {
            if (! isset($names[$scope])) {
                $scopes[] = new RuleScopeData(id: $scope, color: null, environmentCount: 0, overrideCount: $count);
            }
        }

        return $scopes;
    }

    public function alertRules(Team $team, string $scope): array
    {
        $scopeIds = array_map(fn (RuleScopeData $item) => $item->id, $this->ruleScopes($team));

        if (! in_array($scope, $scopeIds, true)) {
            return [];
        }

        return array_map(
            fn (AlertRuleMetric $metric) => AlertRuleData::fromEffective($this->effectiveRules->forScope($team, $scope)->for($metric)),
            AlertRuleMetric::cases(),
        );
    }

    public function notificationSettings(Team $team): NotificationSettingsData
    {
        $settings = $this->notificationSettingsByTeam[$team->id] ??= $team->notificationSetting()->first() ?? false;

        if ($settings === false) {
            return new NotificationSettingsData(
                recipients: [],
                webhookUrl: null,
                webhookSecretSet: false,
                quietFrom: null,
                quietTo: null,
                timezone: NotificationSetting::defaultTimezone(),
                repeatMinutes: NotificationSetting::defaultRepeatMinutes(),
            );
        }

        return new NotificationSettingsData(
            recipients: $settings->recipients,
            webhookUrl: $settings->webhook_url,
            webhookSecretSet: $settings->getRawOriginal('webhook_secret') !== null,
            quietFrom: $this->clockTime($settings->quiet_from),
            quietTo: $this->clockTime($settings->quiet_to),
            timezone: $settings->timezone,
            repeatMinutes: $settings->repeat_minutes,
        );
    }

    private function clockTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
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
    private function queueStatus(EnvironmentState $state, array $queue, RuleSet $rules): EnvironmentStatus
    {
        $breaks = fn (AlertRuleMetric $metric, bool $breached) => $breached && $rules->for($metric)->enabled;

        return match (true) {
            $state->status->isDown(), $state->status === EnvironmentStatus::Paused => $state->status,
            $breaks(AlertRuleMetric::QueueMaxWait, $queue['waitSeconds'] > $rules->for(AlertRuleMetric::QueueMaxWait)->threshold),
            $breaks(AlertRuleMetric::QueuePending, $queue['pending'] > $rules->for(AlertRuleMetric::QueuePending)->threshold),
            $breaks(AlertRuleMetric::WorkersMissing, $queue['workers'] === 0 && $queue['pending'] > 0) => EnvironmentStatus::Degraded,
            default => EnvironmentStatus::Active,
        };
    }

    /**
     * @return array<string, float>
     */
    private function thresholdsOf(Environment $environment): array
    {
        $thresholds = [];

        foreach ($this->effectiveRules->forEnvironment($environment)->rules as $metric => $rule) {
            if ($rule->enabled) {
                $thresholds[$metric] = $rule->threshold;
            }
        }

        return $thresholds;
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
            trend: $watched ? $trend['points'] ?? array_fill(0, StoredReadings::trendPoints(), 0) : [],
            trendPercent: $trend['percent'] ?? null,
            maxWaitSeconds: $this->snapshotNumber($state, 'max_wait_seconds'),
            failedInWindow: $this->snapshotNumber($state, 'failed_in_window'),
            failedWindowMinutes: $this->snapshotNumber($state, 'failed_window_minutes') ?: HorizonStats::DEFAULT_FAILED_WINDOW_MINUTES,
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
            thresholds: $this->thresholdsOf($environment),
        );
    }

    private function snapshotNumber(?EnvironmentState $state, string $column): int
    {
        return (int) $state?->getAttribute("snapshot_{$column}");
    }

    /**
     * @return Builder<Alert>
     */
    private function alertsIn(Team $team, AlertState $state, ?string $application = null): Builder
    {
        $now = $this->now();
        $query = $this->visibleAlerts($team, $application, withDeleted: $state === AlertState::Resolved)
            ->with(['mutedBy:id,name', 'handledBy:id,name']);

        return match ($state) {
            AlertState::Open => $this->bySeverity($query->open()->unmutedAt($now)),
            AlertState::Muted => $this->bySeverity($query->open()->mutedAt($now)),
            AlertState::Resolved => $query
                ->where('alerts.resolved_at', '>=', $this->alertRetentionCutoff())
                ->orderByDesc('alerts.resolved_at')
                ->orderBy('alerts.id'),
        };
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    private function bySeverity(Builder $query): Builder
    {
        return $query
            ->orderByRaw('case when alerts.severity = ? then 0 else 1 end', [AlertSeverity::Critical->value])
            ->orderByDesc('alerts.opened_at')
            ->orderByRaw('array_position(?::text[], alerts.metric::text)', [
                '{'.implode(',', array_map(fn (AlertRuleMetric $metric) => $metric->value, AlertRuleMetric::cases())).'}',
            ])
            ->orderBy('alerts.id');
    }

    /**
     * @return Builder<Alert>
     */
    private function visibleAlerts(Team $team, ?string $application, bool $withDeleted): Builder
    {
        $environments = $this->visibleEnvironmentModels($team);

        if ($application !== null) {
            $environments = $environments->filter(fn (Environment $environment) => $environment->application->slug === $application);
        }

        $withDeleted = $withDeleted && $application === null && $this->alertAbilities($team)['all'];

        return Alert::query()
            ->where('alerts.team_id', $team->id)
            ->where(function (Builder $query) use ($environments, $withDeleted) {
                $query->whereIn('alerts.environment_id', $environments->modelKeys());

                if ($withDeleted) {
                    $query->orWhereNull('alerts.environment_id');
                }
            });
    }

    private function alertRetentionCutoff(): CarbonImmutable
    {
        return $this->now()->utc()->subDays((int) config('horizon-watch.alert_retention_days'));
    }

    /**
     * @return array{mute: bool, handle: bool, all: bool}
     */
    private function alertAbilities(Team $team): array
    {
        $user = $this->currentUser();

        return $this->alertAbilitiesByTeam[$team->id] ??= [
            'mute' => $user->can('muteAlert', $team),
            'handle' => $user->can('handleAnomaly', $team),
            'all' => $this->visible->seesEverything($team, $user),
        ];
    }

    /**
     * @param  EloquentCollection<int, Alert>  $alerts
     * @return array<int, AlertData>
     */
    private function toAlertData(Team $team, EloquentCollection $alerts): array
    {
        if ($alerts->isEmpty()) {
            return [];
        }

        $channels = AlertNotification::query()
            ->whereIn('alert_id', $alerts->modelKeys())
            ->where('status', DeliveryStatus::Sent)
            ->distinct()
            ->get(['alert_id', 'channel'])
            ->groupBy('alert_id');

        $slugs = $this->visibleEnvironmentModels($team)->pluck('slug', 'id');
        $environments = collect($this->environments($team))->keyBy('id');
        $abilities = $this->alertAbilities($team);
        $now = $this->now();

        return $alerts->map(fn (Alert $alert) => $this->makeAlert(
            $alert,
            $environments->get($slugs->get($alert->environment_id ?? 0) ?? ''),
            $now,
            array_values(array_filter(
                NotificationChannel::cases(),
                fn (NotificationChannel $channel) => $channels->get($alert->id)?->contains('channel', $channel) ?? false,
            )),
            $abilities,
        ))->all();
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     * @param  array{mute: bool, handle: bool, all: bool}  $abilities
     */
    private function makeAlert(Alert $alert, ?EnvironmentData $environment, CarbonImmutable $now, array $channels, array $abilities): AlertData
    {
        $open = $alert->resolved_at === null;
        $muted = $alert->muted_indefinitely || $alert->muted_until?->gt($now) === true;
        $minutes = fn (?CarbonImmutable $at) => $at === null ? null : max(0, (int) $at->diffInMinutes($now));

        return new AlertData(
            id: $alert->id,
            state: $alert->state($now),
            severity: $alert->severity,
            metric: $alert->metric,
            threshold: $alert->threshold,
            unit: $alert->unit,
            value: $alert->value,
            longestJob: $this->detailText($alert, 'job'),
            longestJobQueue: $this->detailText($alert, 'queue'),
            queuesWithoutWorkers: is_array($alert->detail['queues'] ?? null)
                ? array_values(array_filter($alert->detail['queues'], is_string(...)))
                : [],
            environmentId: $environment?->id,
            applicationName: $alert->application_name,
            environmentName: $alert->environment_name,
            color: $alert->environment_color,
            environmentStatus: $environment?->status,
            collectionPaused: $open && $environment !== null && ! $environment->pollingEnabled,
            minutesAgo: $minutes($alert->opened_at) ?? 0,
            resolvedMinutesAgo: $minutes($alert->resolved_at),
            mutedUntil: $muted && ! $alert->muted_indefinitely ? $alert->muted_until?->toIso8601String() : null,
            mutedUntilResolved: $alert->muted_indefinitely,
            mutedBy: $muted && $alert->mutedBy !== null ? new AlertActorData($alert->mutedBy->name) : null,
            handledBy: $alert->handledBy !== null ? new AlertActorData($alert->handledBy->name) : null,
            handledMinutesAgo: $minutes($alert->handled_at),
            channels: $channels,
            canMute: $open && $abilities['mute'],
            canHandle: $open && $abilities['handle'],
        );
    }

    private function detailText(Alert $alert, string $key): ?string
    {
        $value = $alert->detail[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
