<?php

namespace App\Monitoring;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\NotificationSettingsData;
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
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Applications, environments, colors, URLs and basic-auth state come from
 * the database, filtered by VisibleEnvironments; the numbers still come
 * from GeneratedMetrics, deterministic per environment slug and tick.
 *
 * The interface only takes a Team — the phase 1 Queries were written before
 * per-member visibility existed, and changing every Query's signature was
 * out of scope for this phase. So the viewer is an *implicit* dependency,
 * read from the authenticated Guard instead of a parameter. This is only
 * safe because every route that reaches a Query passes through
 * EnsureTeamMembership first, which guarantees an authenticated member of
 * the requested team. A caller that resolves this repository without an
 * authenticated user (a console command, a queued job) would break.
 */
class ConfiguredMonitoringRepository implements MonitoringRepository
{
    // Invented in phase 1, kept as-is: only apply to a scope when an
    // environment with that name actually exists in the organization.
    private const OVERRIDES = [
        'production' => ['horizon.master_inactive' => 2, 'queue.pending' => 5000, 'queue.max_wait' => 30],
        'preprod' => ['horizon.master_inactive' => 10],
        'worker-batch' => ['job.runtime' => 900, 'workers.missing' => 2],
    ];

    // The three caches below are per instance and are never invalidated: a
    // repository instance must not span a mutation that changes visibility
    // or the set of environments/applications, or it will keep serving what
    // it saw first. That holds today because the binding is transient (a
    // fresh instance per resolution) and every write in this app ends in a
    // redirect, which resolves a new instance on the next request — nothing
    // currently keeps one instance alive across a write. They are keyed by
    // team but *not* by user, so they also assume the authenticated user
    // never changes within one instance's life: a second Auth::login() on a
    // shared instance (impersonation, Octane, a job that logs users in)
    // would keep serving the first user's visibility.

    /**
     * The team's visible environments, with application eager loaded, fetched
     * from the database at most once per request no matter how many
     * repository methods ask for them (the environment detail page alone
     * asks about eight times) — keyed by team id since nothing here assumes
     * a repository instance only ever serves one team.
     *
     * @var array<int, EloquentCollection<int, Environment>>
     */
    private array $visibleEnvironmentsByTeam = [];

    /**
     * The same environments as above, indexed by slug for O(1) lookup by
     * every method that resolves one environment (environment(), nodes(),
     * queues(), …) instead of scanning the list on every call.
     *
     * @var array<int, Collection<string, Environment>>
     */
    private array $visibleEnvironmentsBySlugByTeam = [];

    /**
     * Applications with zero environments, for members allowed to manage
     * them (see visibleApplications()). A separate query because these
     * applications never show up among the visible environments' eager
     * loaded "application" — there's no environment to carry one.
     *
     * @var array<int, EloquentCollection<int, Application>>
     */
    private array $emptyApplicationsByTeam = [];

    public function __construct(
        private readonly Guard $auth,
        private readonly VisibleEnvironments $visible,
        private readonly GeneratedMetrics $metrics,
    ) {}

    public function applications(Team $team): array
    {
        return $this->visibleApplications($team)
            ->map($this->toApplicationData(...))
            ->all();
    }

    public function application(Team $team, string $applicationId): ?ApplicationData
    {
        $application = $this->visibleApplications($team)->firstWhere('slug', $applicationId);

        return $application ? $this->toApplicationData($application) : null;
    }

    public function environments(Team $team): array
    {
        return $this->visibleEnvironmentModels($team)
            ->map($this->toEnvironmentData(...))
            ->all();
    }

    public function environment(Team $team, string $environmentId): ?EnvironmentData
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->toEnvironmentData($environment) : null;
    }

    public function nodes(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->metrics->nodes($environment, $this->metrics->metricsFor($environment)) : [];
    }

    public function queues(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->metrics->queues($environment, $this->metrics->metricsFor($environment)) : [];
    }

    public function failedJobs(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->metrics->failedJobs($environment) : [];
    }

    public function longRunningJobs(Team $team, string $environmentId): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        return $environment ? $this->metrics->longRunningJobs($environment, $this->metrics->metricsFor($environment)) : [];
    }

    public function throughputSeries(Team $team, ?string $environmentId, SeriesRange $range): array
    {
        if ($environmentId !== null && $this->findEnvironment($team, $environmentId) === null) {
            return [];
        }

        return $this->metrics->throughputSeries($environmentId, $range);
    }

    public function maxWaitSeries(Team $team, string $environmentId, SeriesRange $range): array
    {
        $environment = $this->findEnvironment($team, $environmentId);

        if ($environment === null) {
            return [];
        }

        return $this->metrics->maxWaitSeries($environmentId, $this->metrics->metricsFor($environment)->status->isDown(), $range);
    }

    public function alerts(Team $team, AlertState $state): array
    {
        $environments = $this->environments($team);
        usort($environments, EnvironmentData::compareBySeverityThenPending(...));

        $unhealthy = array_values(array_filter($environments, fn (EnvironmentData $environment) => ! $environment->status->isHealthy()));
        $healthy = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->status->isHealthy()));

        return match ($state) {
            AlertState::Open => array_map(
                fn (EnvironmentData $environment, int $index) => $this->makeAlert($environment, $state, $this->openMetric($environment, $index), 3 + $index * 11),
                $unhealthy,
                array_keys($unhealthy),
            ),
            // Guarded: fewer healthy environments (a smaller incident table, or a
            // looser $r > 0.93 rule) must yield fewer sample alerts, not a null passed
            // to makeAlert().
            AlertState::Muted => isset($healthy[0])
                ? [$this->makeAlert($healthy[0], $state, AlertRuleMetric::WorkersMissing, 95)]
                : [],
            AlertState::Resolved => array_values(array_filter([
                isset($healthy[1]) ? $this->makeAlert($healthy[1], $state, AlertRuleMetric::QueuePending, 140) : null,
                isset($healthy[2]) ? $this->makeAlert($healthy[2], $state, AlertRuleMetric::QueueMaxWait, 310) : null,
            ])),
        };
    }

    public function sentNotifications(Team $team): array
    {
        // Unchanged from phase 1: sample data for the notification log,
        // out of scope until phase 4 wires up real delivery.
        return [
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::CriticalAlert, 'Fatturaomatic · production', 4),
            new SentNotificationData(NotificationChannel::Webhook, SentNotificationKind::WebhookDelivery, 'hooks.example.com/horizon · 200', 4),
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::WarningDigest, '3', 18),
            new SentNotificationData(NotificationChannel::Mail, SentNotificationKind::Resolved, 'Billing Sync · preprod', 52),
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
        // Defaults only: phase 4 makes these configurable per organization.
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
     * Applications with at least one visible environment, in creation order.
     * Derived from the visible environments (whose application is already
     * eager loaded, so this costs no extra query) instead of $team->applications()
     * directly: an application every one of whose environments is hidden
     * from this member must not appear either (spec: "Un applicativo di cui
     * non si vede nessun ambiente non compare nell'elenco").
     *
     * Exception: an application with *zero* environments (not one whose
     * environments are merely hidden) still appears to a member who can
     * manage applications, or an admin who empties one via DeleteEnvironment
     * would lose it forever — an orphaned row nobody could ever reach again
     * to add an environment to. A restricted member still doesn't see it:
     * there's nothing they could do about it anyway.
     *
     * @return Collection<int, Application>
     */
    private function visibleApplications(Team $team): Collection
    {
        $applications = $this->visibleEnvironmentModels($team)
            ->pluck('application')
            ->unique('id');

        if ($this->currentUser()->hasTeamPermission($team, TeamPermission::ManageApplications)) {
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

    private function findEnvironment(Team $team, string $environmentId): ?Environment
    {
        $bySlug = $this->visibleEnvironmentsBySlugByTeam[$team->id]
            ??= $this->visibleEnvironmentModels($team)->keyBy('slug');

        return $bySlug->get($environmentId);
    }

    /**
     * See the class docblock: an authenticated member of the requested team
     * is guaranteed by EnsureTeamMembership before any Query reaches here.
     */
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

    private function toEnvironmentData(Environment $environment): EnvironmentData
    {
        $metrics = $this->metrics->metricsFor($environment);

        return new EnvironmentData(
            id: $environment->slug,
            applicationId: $environment->application->slug,
            applicationName: $environment->application->name,
            name: $environment->name,
            color: $environment->color,
            horizonUrl: $environment->horizon_url,
            status: $metrics->status,
            pending: $metrics->pending,
            maxWaitSeconds: $metrics->maxWaitSeconds,
            failedLast24Hours: $metrics->failedLast24Hours,
            workers: $metrics->workers,
            jobsPerMinute: $metrics->jobsPerMinute,
            nodeCount: $metrics->nodeCount,
            basicAuthUser: $environment->basic_auth_user,
            redisMemoryGb: $metrics->redisMemoryGb,
            latencyMs: $metrics->latencyMs,
        );
    }

    private function makeAlert(EnvironmentData $environment, AlertState $state, AlertRuleMetric $metric, int $minutesAgo): AlertData
    {
        return new AlertData(
            id: "{$environment->id}:{$metric->value}",
            state: $state,
            severity: $metric->defaultSeverity(),
            metric: $metric,
            threshold: $metric->defaultThreshold(),
            unit: $metric->unit(),
            environmentId: $environment->id,
            applicationName: $environment->applicationName,
            environmentName: $environment->name,
            color: $environment->color,
            environmentStatus: $environment->status,
            nodeCount: $environment->nodeCount,
            pending: $environment->pending,
            maxWaitSeconds: $environment->maxWaitSeconds,
            minutesAgo: $minutesAgo,
            channels: [NotificationChannel::Mail, NotificationChannel::Webhook],
        );
    }

    private function openMetric(EnvironmentData $environment, int $index): AlertRuleMetric
    {
        return match (true) {
            $environment->status === EnvironmentStatus::Unreachable => AlertRuleMetric::EndpointUnreachable,
            $environment->status === EnvironmentStatus::Inactive => AlertRuleMetric::HorizonMasterInactive,
            $index % 2 === 1 => AlertRuleMetric::QueueMaxWait,
            default => AlertRuleMetric::QueuePending,
        };
    }
}
