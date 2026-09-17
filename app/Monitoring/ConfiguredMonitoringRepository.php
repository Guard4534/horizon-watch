<?php

namespace App\Monitoring;

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

/**
 * Applications, environments, colors, URLs and basic-auth state come from
 * the database, filtered by VisibleEnvironments; the numbers come from the
 * stored Horizon readings, through StoredReadings, which never filters.
 *
 * The Applications pages are the one exception to that filter — "la
 * visibilità non è un permesso" — and the note above visibleApplications()
 * is where that split is decided and explained.
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

    // The caches below are per instance and are never invalidated: a
    // repository instance must not span a mutation that changes visibility
    // or the set of environments/applications, or it will keep serving what
    // it saw first. They are keyed by team but *not* by user, so they also
    // assume the authenticated user never changes within one instance's
    // life.
    //
    // The binding is scoped (see MonitoringRepository), which keeps both
    // assumptions: one instance per request, and a request has one
    // authenticated user and never reads a page after its own write — every
    // write in this app ends in a redirect, and the page is the next
    // request, with a new instance. Under php-fpm each request is a new
    // application anyway; Octane flushes scoped instances between requests;
    // the queue worker flushes them before every job (Worker's resetScope),
    // and no job resolves this class (it needs an authenticated member).
    // The feature tests send several requests through one application, so
    // their TestCase flushes scoped instances before each request, as a
    // real server would. What still breaks it: a second Auth::login() or a
    // write followed by a read inside one request (impersonation, a job
    // that logs users in) — resolve a fresh ConfiguredMonitoringRepository
    // there, which is not scoped.

    // They are also keyed by *view* where the two views differ (see the
    // note above visibleApplications()): a cache must never hand the
    // visibility-filtered list to a caller that asked for the whole
    // organization, or the other way round.

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
     * queues(), …) instead of scanning the list on every call. Filtered,
     * like the list it indexes: see findEnvironment().
     *
     * @var array<int, Collection<string, Environment>>
     */
    private array $visibleEnvironmentsBySlugByTeam = [];

    /**
     * The whole organization's environments, for the Applications pages of
     * a member who may manage applications — a different list from the one
     * above, hence a second cache (see the note above visibleApplications()).
     *
     * @var array<int, EloquentCollection<int, Environment>>
     */
    private array $configurableEnvironmentsByTeam = [];

    /**
     * The whole organization's applications, same branch as above.
     *
     * @var array<int, EloquentCollection<int, Application>>
     */
    private array $configurableApplicationsByTeam = [];

    /**
     * Applications with zero environments, for members allowed to manage
     * them (see visibleApplications()). A separate query because these
     * applications never show up among the visible environments' eager
     * loaded "application" — there's no environment to carry one.
     *
     * @var array<int, EloquentCollection<int, Application>>
     */
    private array $emptyApplicationsByTeam = [];

    /**
     * The latest state of every environment this instance has already
     * resolved, by environment id, with a null for "no reading yet". Keyed
     * by environment rather than by team or view: a row is only ever looked
     * up for an Environment model that one of the lists above handed out,
     * so the visibility decision stays with those lists, and the wider
     * configuration view only fetches the rows the watched view did not.
     * One query per view per request at most, however many rows ask.
     *
     * @var array<int, EnvironmentState|null>
     */
    private array $statesByEnvironment = [];

    /**
     * The pending trend of the same environments, fetched with their states
     * and under the same rule: one query per view per request at most, for
     * environments one of the filtered lists handed out.
     *
     * @var array<int, array{points: list<int>, percent: int|null}>
     */
    private array $trendsByEnvironment = [];

    /**
     * The open anomalies of the watched environments, by environment id.
     * Watched view only: alerts are never read from the configuration view.
     *
     * @var array<int, array<int, list<array{metric: AlertRuleMetric, since: CarbonImmutable, truncated: bool}>>>
     */
    private array $openAnomaliesByTeam = [];

    /**
     * Whether the viewer may manage the team's applications. Memoized
     * because it now decides which of the two views answers and every list
     * asks it, at an indexed membership read each time.
     *
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

        // Resolved from the watched list, so it is watched by definition.
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
            // The detail of a failed reading is the last one that worked:
            // what those masters do now is unknown, so they carry the
            // environment's status instead of their stale one.
            status: match (true) {
                $state->status->isDown() => $state->status,
                $node['status'] === 'paused' => EnvironmentStatus::Paused,
                default => EnvironmentStatus::Active,
            },
            workers: $node['workers'],
            supervisorCount: $node['supervisors'],
            queueCount: $node['queues'],
            // States written before nodes carried seenAt date them from the
            // reading itself. A worker clock ahead of this one reads as 0.
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
        // The default, not the scope's override (900 s for worker-batch):
        // overrides are invented until phase 4 makes them real.
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
            // The organization's line is the sum of what this viewer
            // watches, never of the whole organization.
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

    /**
     * Open anomalies are computed from the stored readings, worst
     * environment first and worst anomaly first within it. Muting and
     * resolving arrive with phase 4, and so do their lists.
     */
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

            // A snapshot without a state is never written (the poller
            // writes both in one transaction): nothing to describe it with.
            if ($model === null || $environment->status === null) {
                continue;
            }

            foreach ($anomalies[$model->id] ?? [] as $anomaly) {
                $alerts[] = $this->makeAlert(
                    $environment,
                    $environment->status,
                    $anomaly['metric'],
                    $anomaly['truncated'] ? $cap : min($cap, max(0, (int) $anomaly['since']->diffInMinutes($now))),
                    $anomaly['truncated'],
                );
            }
        }

        return $alerts;
    }

    public function sentNotifications(Team $team): array
    {
        // Sample data until phase 4 wires up real delivery, but named after
        // environments this viewer watches: a hard-coded subject would tell
        // a restricted member about an environment hidden from them.
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
     * ── The visibility split, and where the permission wins ──────────────
     *
     * Two readings of the same organization live in this class, because the
     * spec asks for two (phase 2 spec, "Visibilità degli ambienti"):
     *
     * - The *watched* view — the wall, the alerts, the alert-rule scopes,
     *   the environment detail page and every count that says how much of
     *   the organization this member is watching — is visibility-filtered
     *   for everybody, permission or not. "Un ambiente non visibile non
     *   compare da nessuna parte e la sua pagina risponde 404" is about
     *   this view. It is served by visibleApplications() and
     *   visibleEnvironmentModels().
     * - The *configuration* view — the Applications pages — answers to the
     *   permission instead: "la visibilità non è un permesso: un admin con
     *   `manual` vede solo i suoi ambienti, ma li configura tutti dalla
     *   vista Applicativi (dove serve il permesso, non la visibilità). Il
     *   caso è raro". A member holding TeamPermission::ManageApplications
     *   therefore gets the whole organization from the configurable*
     *   methods; everybody else gets exactly what they watch, which is all
     *   they could act on anyway.
     *
     * Without the split, the rare case the spec calls out had no way
     * through the interface at all: a restricted admin was told a hidden
     * environment's name by the Members view, got no link to it from the
     * Applications view, and could only fix its credentials by typing the
     * edit URL from memory. The write pages themselves never come through
     * this class — route model binding, then the Policy — which is why they
     * already answered 200 to that admin.
     *
     * Applications with at least one visible environment, in creation order.
     * Derived from the visible environments (whose application is already
     * eager loaded, so this costs no extra query) instead of $team->applications()
     * directly: an application every one of whose environments is hidden
     * from this member must not appear either (spec: "Un applicativo di cui
     * non si vede nessun ambiente non compare nell'elenco").
     *
     * Exception: an application with *zero* environments (not one whose
     * environments are merely hidden) still appears to a member who can
     * manage applications. It survived the split — the configuration view
     * lists it anyway now — because this list also feeds the wall's
     * applicationCount, which is how the wall tells "this application has
     * no environment yet" from "nothing is configured yet" and from
     * "something is hidden from you" (see EmptyStateTest). A restricted
     * member still doesn't see it: there's nothing they could do about it
     * anyway.
     *
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
     * The Applications pages' applications: the whole organization for a
     * member who may manage them, the watched list for everybody else (see
     * the note above visibleApplications()). Read from $team->applications()
     * rather than derived from the environments, since an application whose
     * every environment is hidden — the very case this branch exists for —
     * carries none of them.
     *
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
     * The Applications pages' environments, same rule as above.
     *
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

    /**
     * Resolves one environment by slug for the pages that read *one*
     * environment: the detail page and the panels, series and job tables it
     * is made of. Deliberately the watched list, permission or not — the
     * detail page is the operational view, and the spec has a hidden
     * environment's page answering 404 there. A restricted admin configures
     * that environment from the Applications view instead, whose edit link
     * goes to a route-bound page that never asks this class.
     */
    private function findEnvironment(Team $team, string $environmentId): ?Environment
    {
        return $this->watchedEnvironmentsBySlug($team)->get($environmentId);
    }

    /**
     * The watched environments by slug: the map findEnvironment() answers
     * from, and the one configurableEnvironments() asks whether each of its
     * rows is on this viewer's wall.
     *
     * @return Collection<string, Environment>
     */
    private function watchedEnvironmentsBySlug(Team $team): Collection
    {
        return $this->visibleEnvironmentsBySlugByTeam[$team->id]
            ??= $this->visibleEnvironmentModels($team)->keyBy('slug');
    }

    /**
     * Fetches the states and trends of the given environments that this
     * instance has not seen yet, in one query each (see
     * $statesByEnvironment). The two caches are filled together, so an
     * environment missing from one is missing from the other.
     *
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

    /**
     * The state of a watched environment. Loads the whole watched list at
     * once, since a page that asks for one panel asks for the others too.
     */
    private function stateOf(Team $team, Environment $environment): ?EnvironmentState
    {
        if (! array_key_exists($environment->id, $this->statesByEnvironment)) {
            $this->loadStates($this->visibleEnvironmentModels($team));
        }

        return $this->statesByEnvironment[$environment->id] ?? null;
    }

    /**
     * A queue follows its environment when the environment is down or
     * paused; otherwise it is degraded when it breaks a default threshold on
     * its own, or has work and nobody to do it. The pending threshold is the
     * environment-wide one, reused per queue on purpose: phase 4 brings
     * per-rule thresholds, not per-queue ones.
     *
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

    /**
     * Never pass this as a first-class callable to map(): the second
     * argument would arrive as the collection key, which is an int, and
     * every row would silently report itself unwatched.
     *
     * An unwatched row (the configuration view of a restricted admin)
     * carries no reading at all: its configuration is theirs to fix, its
     * operations are not theirs to watch.
     */
    private function toEnvironmentData(Environment $environment, bool $watched): EnvironmentData
    {
        // Callers load the states of their whole list first: a miss here
        // would be one query per row.
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
            horizonUrl: self::withoutUserinfo($environment->horizon_url),
            status: match (true) {
                ! $watched => null,
                $state !== null => $state->status,
                // Never read: waiting for the first poll until that is
                // overdue by the stale rule, then reported as not answering.
                $stale => EnvironmentStatus::Unreachable,
                default => null,
            },
            pending: $this->snapshotNumber($state, 'pending'),
            trend: $watched ? $trend['points'] ?? array_fill(0, StoredReadings::TREND_POINTS, 0) : [],
            trendPercent: $trend['percent'] ?? null,
            maxWaitSeconds: $this->snapshotNumber($state, 'max_wait_seconds'),
            failedLast24Hours: $this->snapshotNumber($state, 'failed_last_24_hours'),
            // Without a snapshot there is no count to qualify: Horizon's
            // default week, as the column default.
            failedWindowMinutes: $this->snapshotNumber($state, 'failed_window_minutes') ?: 10080,
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
        );
    }

    /**
     * Rows saved before the form refused credentials in the URL may still
     * carry "user:secret@": never hand that to a page.
     */
    private static function withoutUserinfo(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/?\#]*@#i', '$1', $url);
    }

    /**
     * A number of the latest snapshot, attached to the state by
     * StoredReadings::latestFor(); 0 without a state or a snapshot.
     */
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
            channels: [NotificationChannel::Mail, NotificationChannel::Webhook],
        );
    }
}
