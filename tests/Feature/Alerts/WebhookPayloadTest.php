<?php

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\AlertRuleMetric;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

const PAYLOAD_DELIVERY_ID = '0f6d2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f';

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-09-17 12:02:04', 'UTC');
    $this->travelTo($this->now);
    URL::forceRootUrl('https://horizon-watch.example.com');
    URL::forceScheme('https');
    config(['app.locale' => 'en']);

    $this->team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $application = Application::factory()->for($this->team)->create(['name' => 'Shop', 'slug' => 'shop']);
    $this->environment = Environment::factory()->for($application)->production()->create();
});

function payloadAlert(Environment $environment, array $attributes): Alert
{
    return Alert::factory()->for($environment)->create([
        'opened_at' => CarbonImmutable::parse('2026-09-17 12:02:00', 'UTC'),
        ...$attributes,
    ])->load(['team', 'environment']);
}

/**
 * @return array<string, mixed>
 */
function sentPayload(array $payload): array
{
    return WebhookPayload::stamped($payload, CarbonImmutable::now(), PAYLOAD_DELIVERY_ID);
}

/**
 * @return list<array<string, mixed>>
 */
function readmeWebhookExamples(): array
{
    $readme = (string) file_get_contents(base_path('README.md'));
    $section = substr($readme, (int) strpos($readme, '### Webhook'));
    $section = substr($section, 0, (int) strpos($section, "\n## "));

    preg_match_all('/```json\n(.*?)\n```/s', $section, $blocks);

    return array_map(fn (string $block) => json_decode($block, true, flags: JSON_THROW_ON_ERROR), $blocks[1]);
}

/**
 * @return array<string, mixed>
 */
function payloadShape(array $payload): array
{
    return array_map(fn (mixed $value) => is_array($value) && ! array_is_list($value) ? payloadShape($value) : true, $payload);
}

test('an opened critical alert is sent as the version 1 payload', function () {
    $alert = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::HorizonMasterInactive,
        'severity' => 'critical',
        'value' => 14,
        'detail' => [],
    ]);

    expect(sentPayload(WebhookPayload::forAlert($alert, WebhookPayload::OPENED)))->toBe([
        'version' => 1,
        'event' => 'alert.opened',
        'delivery_id' => PAYLOAD_DELIVERY_ID,
        'alert' => [
            'id' => $alert->id,
            'rule' => 'horizon.master_inactive',
            'severity' => 'critical',
            'summary' => 'Shop · production — Horizon inactive for 14 min',
            'value' => 14.0,
            'threshold' => 5.0,
            'unit' => 'min',
            'detail' => [
                'value_is_lower_bound' => false,
                'longest_job' => null,
                'longest_job_queue' => null,
                'queues_without_workers' => [],
            ],
            'application' => 'Shop',
            'environment' => 'production',
            'opened_at' => '2026-09-17T12:02:00Z',
            'resolved_at' => null,
            'url' => 'https://horizon-watch.example.com/acme/environments/shop-production',
        ],
        'organization' => ['name' => 'Acme', 'slug' => 'acme'],
        'sent_at' => '2026-09-17T12:02:04Z',
    ]);
});

test('a repeated critical alert carries the running job and its queue', function () {
    $alert = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::JobRuntime,
        'severity' => 'critical',
        'value' => 300,
        'detail' => ['job' => 'App\\Jobs\\ImportCatalog', 'queue' => 'imports', 'seconds' => 300],
    ]);

    expect(sentPayload(WebhookPayload::forAlert($alert, WebhookPayload::REPEATED)))->toBe([
        'version' => 1,
        'event' => 'alert.repeated',
        'delivery_id' => PAYLOAD_DELIVERY_ID,
        'alert' => [
            'id' => $alert->id,
            'rule' => 'job.runtime',
            'severity' => 'critical',
            'summary' => 'Shop · production — A job has been running for 300 s',
            'value' => 300.0,
            'threshold' => 120.0,
            'unit' => 's',
            'detail' => [
                'value_is_lower_bound' => false,
                'longest_job' => 'App\\Jobs\\ImportCatalog',
                'longest_job_queue' => 'imports',
                'queues_without_workers' => [],
            ],
            'application' => 'Shop',
            'environment' => 'production',
            'opened_at' => '2026-09-17T12:02:00Z',
            'resolved_at' => null,
            'url' => 'https://horizon-watch.example.com/acme/environments/shop-production',
        ],
        'organization' => ['name' => 'Acme', 'slug' => 'acme'],
        'sent_at' => '2026-09-17T12:02:04Z',
    ]);
});

test('a resolved critical alert says it is back within the threshold', function () {
    $alert = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'severity' => 'critical',
        'value' => 3,
        'detail' => [],
        'resolved_at' => CarbonImmutable::parse('2026-09-17 12:02:03', 'UTC'),
    ]);

    expect(sentPayload(WebhookPayload::forAlert($alert, WebhookPayload::RESOLVED)))->toBe([
        'version' => 1,
        'event' => 'alert.resolved',
        'delivery_id' => PAYLOAD_DELIVERY_ID,
        'alert' => [
            'id' => $alert->id,
            'rule' => 'endpoint.unreachable',
            'severity' => 'critical',
            'summary' => 'Shop · production — Back within the threshold: Endpoint unreachable',
            'value' => 3.0,
            'threshold' => 2.0,
            'unit' => 'min',
            'detail' => [
                'value_is_lower_bound' => false,
                'longest_job' => null,
                'longest_job_queue' => null,
                'queues_without_workers' => [],
            ],
            'application' => 'Shop',
            'environment' => 'production',
            'opened_at' => '2026-09-17T12:02:00Z',
            'resolved_at' => '2026-09-17T12:02:03Z',
            'url' => 'https://horizon-watch.example.com/acme/environments/shop-production',
        ],
        'organization' => ['name' => 'Acme', 'slug' => 'acme'],
        'sent_at' => '2026-09-17T12:02:04Z',
    ]);
});

test('a digest lists every warning with its own detail and summary', function () {
    $failed = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::JobsFailedPerHour,
        'severity' => 'warning',
        'value' => 400,
        'detail' => ['failed' => 400, 'atLeast' => true],
    ]);
    $workers = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::WorkersMissing,
        'severity' => 'warning',
        'value' => 5,
        'detail' => ['queues' => ['emails', 'imports', 7]],
        'opened_at' => CarbonImmutable::parse('2026-09-17 11:40:00', 'UTC'),
        'resolved_at' => CarbonImmutable::parse('2026-09-17 11:55:00', 'UTC'),
    ]);

    $payload = sentPayload(WebhookPayload::forDigest($this->team, collect([$workers, $failed])));

    expect($payload)->toBe([
        'version' => 1,
        'event' => 'alert.digest',
        'delivery_id' => PAYLOAD_DELIVERY_ID,
        'alerts' => [
            [
                'id' => $workers->id,
                'rule' => 'workers.missing',
                'severity' => 'warning',
                'summary' => 'Shop · production — Back within the threshold: Missing workers',
                'value' => 5.0,
                'threshold' => 4.0,
                'unit' => '',
                'detail' => [
                    'value_is_lower_bound' => false,
                    'longest_job' => null,
                    'longest_job_queue' => null,
                    'queues_without_workers' => ['emails', 'imports'],
                ],
                'application' => 'Shop',
                'environment' => 'production',
                'opened_at' => '2026-09-17T11:40:00Z',
                'resolved_at' => '2026-09-17T11:55:00Z',
                'url' => 'https://horizon-watch.example.com/acme/environments/shop-production',
            ],
            [
                'id' => $failed->id,
                'rule' => 'jobs.failed_per_hour',
                'severity' => 'warning',
                'summary' => 'Shop · production — at least 400 jobs failed in the last hour',
                'value' => 400.0,
                'threshold' => 20.0,
                'unit' => 'job',
                'detail' => [
                    'value_is_lower_bound' => true,
                    'longest_job' => null,
                    'longest_job_queue' => null,
                    'queues_without_workers' => [],
                ],
                'application' => 'Shop',
                'environment' => 'production',
                'opened_at' => '2026-09-17T12:02:00Z',
                'resolved_at' => null,
                'url' => 'https://horizon-watch.example.com/acme/environments/shop-production',
            ],
        ],
        'organization' => ['name' => 'Acme', 'slug' => 'acme'],
        'sent_at' => '2026-09-17T12:02:04Z',
    ]);
});

test('a test delivery has no alert', function () {
    expect(sentPayload(WebhookPayload::forTest($this->team)))->toBe([
        'version' => 1,
        'event' => 'test',
        'delivery_id' => PAYLOAD_DELIVERY_ID,
        'alert' => null,
        'organization' => ['name' => 'Acme', 'slug' => 'acme'],
        'sent_at' => '2026-09-17T12:02:04Z',
    ]);
});

test('the summary is written in the language of the panel', function () {
    App::setLocale('it');

    $alert = payloadAlert($this->environment, [
        'metric' => AlertRuleMetric::QueuePending,
        'severity' => 'critical',
        'value' => 2500,
        'detail' => ['pending' => 2500],
    ]);

    expect(WebhookPayload::forAlert($alert, WebhookPayload::OPENED)['alert']['summary'])->toBe('Shop · production — 2.500 job pending');
});

test('the readme examples have the shape of what is sent', function () {
    $opened = payloadAlert($this->environment, ['metric' => AlertRuleMetric::HorizonMasterInactive, 'severity' => 'critical', 'value' => 14, 'detail' => []]);
    $warning = payloadAlert($this->environment, ['metric' => AlertRuleMetric::JobRuntime, 'severity' => 'warning', 'value' => 300, 'detail' => ['job' => 'App\\Jobs\\ImportCatalog', 'queue' => 'imports']]);

    [$alertExample, $digestExample] = readmeWebhookExamples();

    expect(payloadShape($alertExample))->toBe(payloadShape(sentPayload(WebhookPayload::forAlert($opened, WebhookPayload::OPENED))))
        ->and(payloadShape($digestExample))->toBe(payloadShape(sentPayload(WebhookPayload::forDigest($this->team, collect([$warning])))))
        ->and(array_map(payloadShape(...), $digestExample['alerts']))->each->toBe(payloadShape(sentPayload(WebhookPayload::forAlert($warning, WebhookPayload::OPENED))['alert']));
});
