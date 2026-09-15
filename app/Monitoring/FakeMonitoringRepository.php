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
use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
use App\Enums\NotificationChannel;
use App\Enums\RuleOrigin;
use App\Enums\SentNotificationKind;
use App\Enums\SeriesRange;
use App\Models\Team;

/**
 * Stands in for real Horizon readings until polling exists. Every team sees
 * the same organization; numbers depend only on the current 15-second tick.
 */
class FakeMonitoringRepository implements MonitoringRepository
{
    private const TICK_SECONDS = 15;

    private const SERIES_POINTS = 48;

    private const APPLICATIONS = [
        'fatturaomatic' => ['Fatturaomatic', 'fatturaomatic.example.com', ['production', 'preprod', 'staging', 'develop']],
        'acme-shop' => ['Acme Shop', 'shop.example.com', ['production', 'staging', 'develop', 'demo']],
        'logistics-hub' => ['Logistics Hub', 'logistics.example.com', ['production', 'worker-batch', 'staging']],
        'crm-bridge' => ['CRM Bridge', 'crm-bridge.example.com', ['production', 'preprod', 'testing']],
        'mailer-service' => ['Mailer Service', 'mailer.example.net', ['production', 'worker-batch', 'staging', 'develop']],
        'media-encoder' => ['Media Encoder', 'media.example.net', ['production', 'worker-batch', 'testing']],
        'partner-api' => ['Partner API', 'api.example.org', ['production', 'staging', 'demo']],
        'billing-sync' => ['Billing Sync', 'billing.example.com', ['production', 'preprod', 'develop']],
        'internal-tools' => ['Internal Tools', 'tools.example.com', ['production', 'staging']],
    ];

    private const COLORS = [
        'production' => EnvironmentColor::Prod,
        'preprod' => EnvironmentColor::Preprod,
        'staging' => EnvironmentColor::Staging,
        'develop' => EnvironmentColor::Develop,
        'demo' => EnvironmentColor::Demo,
        'worker-batch' => EnvironmentColor::Worker,
        'testing' => EnvironmentColor::Testing,
    ];

    // Fixed incidents, so the wall always tells the same story while the rest drifts.
    private const INCIDENTS = [
        'fatturaomatic-production' => EnvironmentStatus::Inactive,
        'mailer-service-worker-batch' => EnvironmentStatus::Degraded,
        'logistics-hub-worker-batch' => EnvironmentStatus::Unreachable,
        'acme-shop-staging' => EnvironmentStatus::Paused,
        'media-encoder-production' => EnvironmentStatus::Degraded,
        'billing-sync-preprod' => EnvironmentStatus::Degraded,
    ];

    private const RULE_SCOPES = ['organization', 'production', 'preprod', 'staging', 'worker-batch'];

    private const OVERRIDES = [
        'production' => ['horizon.master_inactive' => 2, 'queue.pending' => 5000, 'queue.max_wait' => 30],
        'preprod' => ['horizon.master_inactive' => 10],
        'worker-batch' => ['job.runtime' => 900, 'workers.missing' => 2],
    ];

    public function applications(Team $team): array
    {
        $applications = [];

        foreach (self::APPLICATIONS as $id => [$name, $host]) {
            $applications[] = new ApplicationData($id, $name, $host);
        }

        return $applications;
    }

    public function application(Team $team, string $applicationId): ?ApplicationData
    {
        foreach ($this->applications($team) as $application) {
            if ($application->id === $applicationId) {
                return $application;
            }
        }

        return null;
    }

    public function environments(Team $team): array
    {
        $tick = $this->tick();
        $environments = [];

        foreach (self::APPLICATIONS as $applicationId => [$name, $host, $environmentNames]) {
            foreach ($environmentNames as $environmentName) {
                $environments[] = $this->makeEnvironment($applicationId, $name, $host, $environmentName, $tick);
            }
        }

        return $environments;
    }

    public function environment(Team $team, string $environmentId): ?EnvironmentData
    {
        foreach ($this->environments($team) as $environment) {
            if ($environment->id === $environmentId) {
                return $environment;
            }
        }

        return null;
    }

    public function nodes(Team $team, string $environmentId): array
    {
        $environment = $this->environment($team, $environmentId);

        if ($environment === null) {
            return [];
        }

        $prefixes = match ($environment->name) {
            'production' => ['queue-01', 'queue-02', 'queue-03'],
            'worker-batch' => ['batch-01', 'batch-02'],
            default => ['app-01'],
        };
        $down = $environment->status->isDown();
        $divisor = $environment->name === 'production' ? 3 : 2;
        $queueCount = count($this->queueNames($environment));
        $nodes = [];

        foreach ($prefixes as $index => $prefix) {
            $nodeDown = $down && $index === 0;

            $nodes[] = new NodeData(
                hostname: "{$prefix}.{$environment->applicationId}.internal",
                status: $nodeDown ? $environment->status : ($down ? EnvironmentStatus::Degraded : $environment->status),
                workers: $nodeDown ? 0 : max(1, (int) round($environment->workers / $divisor)),
                jobsPerMinute: $nodeDown ? 0 : (int) round($environment->jobsPerMinute / $divisor),
                memoryMb: (int) round(120 + $this->unit($environment->id.$prefix) * 260),
                supervisorCount: $index === 0 ? 2 : 1,
                queueCount: $nodeDown ? 0 : min($queueCount, 2 + $index),
                lastHeartbeatSecondsAgo: $nodeDown ? 840 : 2,
            );
        }

        return $nodes;
    }

    public function queues(Team $team, string $environmentId): array
    {
        $environment = $this->environment($team, $environmentId);

        if ($environment === null) {
            return [];
        }

        $shares = [0.42, 0.24, 0.16, 0.11, 0.07];
        $down = $environment->status->isDown();
        $queues = [];

        foreach ($this->queueNames($environment) as $index => $name) {
            $r = $this->unit($environment->id.$name);
            $wait = $down ? $environment->maxWaitSeconds : (int) round($environment->maxWaitSeconds * (0.5 + $r));

            $queues[] = new QueueData(
                name: $name,
                supervisor: "{$environment->name}-supervisor-".($index < 2 ? 1 : 2),
                workers: $down ? 0 : max(1, (int) round($environment->workers * $shares[$index])),
                pending: (int) round($environment->pending * $shares[$index]),
                waitSeconds: $wait,
                runtimeSeconds: round(0.4 + $r * 6, 1),
                status: match (true) {
                    $down => EnvironmentStatus::Inactive,
                    $wait >= 300 => EnvironmentStatus::Degraded,
                    $environment->status === EnvironmentStatus::Paused => EnvironmentStatus::Paused,
                    default => EnvironmentStatus::Active,
                },
            );
        }

        return $queues;
    }

    public function failedJobs(Team $team, string $environmentId): array
    {
        $environment = $this->environment($team, $environmentId);

        if ($environment === null) {
            return [];
        }

        $jobs = ['App\\Jobs\\SendInvoiceMail', 'App\\Jobs\\SyncCustomer', 'App\\Jobs\\GenerateReport', 'App\\Jobs\\PushWebhook', 'App\\Jobs\\RebuildIndex'];
        $exceptions = [
            'Illuminate\\Database\\QueryException: deadlock found',
            'GuzzleHttp\\Exception\\ConnectException: cURL error 28',
            'RedisException: read error on connection',
            'Symfony\\Component\\Mailer\\Exception\\TransportException',
            'App\\Exceptions\\PayloadTooLarge',
        ];
        $queueNames = $this->queueNames($environment);
        $failed = [];

        foreach ($jobs as $index => $job) {
            $failed[] = new FailedJobData(
                job: $job,
                queue: $queueNames[$index % count($queueNames)],
                exception: $exceptions[$index],
                tries: $index % 3 + 1,
                minutesAgo: 2 + $index * 7,
            );
        }

        return $failed;
    }

    public function longRunningJobs(Team $team, string $environmentId): array
    {
        $environment = $this->environment($team, $environmentId);

        if ($environment === null) {
            return [];
        }

        $jobs = ['App\\Jobs\\RebuildIndex', 'App\\Jobs\\ExportLedger', 'App\\Jobs\\TranscodeVideo'];
        $queueNames = $this->queueNames($environment);
        $running = [];

        foreach ($jobs as $index => $job) {
            $elapsed = $environment->status->isDown() ? 780 + $index * 260 : 96 + $index * 130;

            $running[] = new LongRunningJobData(
                job: $job,
                queue: $queueNames[$index % count($queueNames)],
                elapsedSeconds: $elapsed,
                startedAt: now()->subSeconds($elapsed)->format('H:i'),
            );
        }

        return $running;
    }

    public function throughputSeries(Team $team, ?string $environmentId, SeriesRange $range): array
    {
        $seed = 'tp'.($environmentId ?? 'organization').$range->value.intdiv($this->tick(), 2);

        return $environmentId === null
            ? $this->series($seed, 60, 70)
            : $this->series($seed, 70, 30);
    }

    public function maxWaitSeries(Team $team, string $environmentId, SeriesRange $range): array
    {
        $down = $this->environment($team, $environmentId)?->status->isDown() ?? false;

        return $this->series('wt'.$environmentId.$range->value.intdiv($this->tick(), 2), $down ? 95 : 30, 8);
    }

    public function alerts(Team $team, AlertState $state): array
    {
        $environments = $this->environments($team);
        usort($environments, fn (EnvironmentData $a, EnvironmentData $b) => [$a->status->severity(), $b->pending] <=> [$b->status->severity(), $a->pending]);

        $unhealthy = array_values(array_filter($environments, fn (EnvironmentData $environment) => ! $environment->status->isHealthy()));
        $healthy = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->status->isHealthy()));

        return match ($state) {
            AlertState::Open => array_map(
                fn (EnvironmentData $environment, int $index) => $this->makeAlert($environment, $state, $this->openMetric($environment, $index), 3 + $index * 11),
                $unhealthy,
                array_keys($unhealthy),
            ),
            AlertState::Muted => [$this->makeAlert($healthy[0], $state, AlertRuleMetric::WorkersMissing, 95)],
            AlertState::Resolved => [
                $this->makeAlert($healthy[1], $state, AlertRuleMetric::QueuePending, 140),
                $this->makeAlert($healthy[2], $state, AlertRuleMetric::QueueMaxWait, 310),
            ],
        };
    }

    public function sentNotifications(Team $team): array
    {
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
        $scopes = [];

        foreach (self::RULE_SCOPES as $scope) {
            $scopes[] = new RuleScopeData(
                id: $scope,
                color: self::COLORS[$scope] ?? null,
                environmentCount: $scope === 'organization'
                    ? count($environments)
                    : count(array_filter($environments, fn (EnvironmentData $environment) => $environment->name === $scope)),
                overrideCount: count(self::OVERRIDES[$scope] ?? []),
            );
        }

        return $scopes;
    }

    public function alertRules(Team $team, string $scope): array
    {
        if (! in_array($scope, self::RULE_SCOPES, true)) {
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

    private function makeEnvironment(string $applicationId, string $applicationName, string $host, string $name, int $tick): EnvironmentData
    {
        $id = "{$applicationId}-{$name}";
        $r = $this->unit($id);
        $r2 = $this->unit($id.'x');
        $production = $name === 'production';
        $drift = sin($tick / 4 + $r * 9) * 0.5 + 0.5;
        // The mockup lets incidents grow for ever; a bounded cycle keeps real clocks sane.
        $cycle = $tick % 40;
        $status = self::INCIDENTS[$id] ?? ($r > 0.93 ? EnvironmentStatus::Degraded : EnvironmentStatus::Active);

        [$pending, $wait] = match (true) {
            $status->isDown() => [(int) round(2800 + $r * 2600 + $cycle * 11), (int) round(780 + $r2 * 500 + $cycle * 3)],
            $status === EnvironmentStatus::Paused => [(int) round(900 + $r * 500), (int) round(300 + $r2 * 200)],
            $status === EnvironmentStatus::Degraded => [(int) round(1400 + $r * 1800 * (0.7 + $drift)), (int) round(120 + $r2 * 260 * (0.6 + $drift))],
            default => [
                (int) round(($production ? 180 + $r * 900 : 12 + $r * 220) * (0.6 + $drift * 0.9)),
                (int) round(($production ? 4 : 2) + $r2 * 40 * (0.4 + $drift)),
            ],
        };

        return new EnvironmentData(
            id: $id,
            applicationId: $applicationId,
            applicationName: $applicationName,
            name: $name,
            color: self::COLORS[$name],
            horizonUrl: 'https://'.($production ? '' : "{$name}.").$host.'/horizon',
            status: $status,
            pending: $pending,
            maxWaitSeconds: $wait,
            failedLast24Hours: $status->isHealthy() ? (int) round($r2 * 6) : (int) round(14 + $r2 * 90 + $cycle),
            workers: $status->isDown() ? 0 : (int) round(3 + $r * 29),
            jobsPerMinute: $status->isDown() ? 0 : (int) round(($production ? 140 : 20) * (0.5 + $drift) + $r * 40),
            nodeCount: match ($name) {
                'production' => 3,
                'worker-batch' => 2,
                default => 1,
            },
            basicAuthUser: in_array($name, ['production', 'preprod'], true) ? 'horizon-bot' : null,
            redisMemoryGb: round(0.6 + $r * 3.4, 1),
            latencyMs: (int) round(90 + $r2 * 600),
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

    /**
     * @return array<int, string>
     */
    private function queueNames(EnvironmentData $environment): array
    {
        return $environment->name === 'worker-batch'
            ? ['batch-import', 'batch-export', 'default']
            : ['default', 'emails', 'notifications', 'invoices', 'webhooks'];
    }

    /**
     * @return array<int, int>
     */
    private function series(string $seed, float $amplitude, float $base): array
    {
        $phase = $this->unit($seed) * 6;
        $values = [];

        for ($i = 0; $i < self::SERIES_POINTS; $i++) {
            $values[] = (int) round($base + abs(sin($i / 3 + $phase)) * $amplitude + $this->unit($seed.$i) * $amplitude * 0.5);
        }

        return $values;
    }

    private function tick(): int
    {
        return intdiv(now()->getTimestamp(), self::TICK_SECONDS);
    }

    /**
     * FNV-1a folded into [0, 1): the same seed always gives the same number.
     */
    private function unit(string $seed): float
    {
        $hash = 2166136261;

        foreach (str_split($seed) as $character) {
            $hash ^= ord($character);
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }

        return $hash / 4294967296;
    }
}
