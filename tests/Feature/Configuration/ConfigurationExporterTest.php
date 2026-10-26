<?php

use App\Configuration\ConfigurationExporter;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentType;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    config(['horizon-watch.version' => '1.0.0']);

    $this->team = Team::factory()->create(['name' => 'Example Organization', 'slug' => 'example-organization']);

    $billing = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    Environment::factory()->for($billing)->create([
        'name' => 'demo',
        'type' => EnvironmentType::Demo,
        'horizon_url' => 'https://demo.billing.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
        'poll_interval_seconds' => 15,
        'polling_enabled' => true,
    ]);

    $this->acme = Application::factory()->for($this->team)->create(['name' => 'Acme Shop']);
    Environment::factory()->for($this->acme)->create([
        'name' => 'production',
        'type' => EnvironmentType::Production,
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => 'ops',
        'basic_auth_password' => 'stored-secret',
        'poll_interval_seconds' => 60,
        'polling_enabled' => true,
    ]);
    Environment::factory()->for($this->acme)->create([
        'name' => 'staging',
        'type' => EnvironmentType::Staging,
        'horizon_url' => 'https://staging.shop.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
        'poll_interval_seconds' => 30,
        'polling_enabled' => false,
    ]);

    AlertRule::factory()->for($this->team)->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 2000,
        'severity' => AlertSeverity::Warning,
        'notify_email' => true,
        'enabled' => true,
        'for_minutes' => null,
    ]);
    AlertRule::factory()->for($this->team)->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'threshold' => 3,
        'severity' => AlertSeverity::Critical,
        'notify_email' => true,
        'enabled' => true,
        'for_minutes' => null,
    ]);
    AlertRule::factory()->for($this->team)->inheriting()->create([
        'scope' => 'production',
        'metric' => AlertRuleMetric::QueueMaxWait,
        'threshold' => 90,
    ]);

    $this->exporter = app(ConfigurationExporter::class);
});

test('the export lists applications, environments and alert rules in the documented shape', function () {
    expect($this->exporter->export($this->team))->toBe([
        'format' => 'horizon-watch.config',
        'version' => 1,
        'exported_at' => '2026-10-16T09:00:00Z',
        'app_version' => '1.0.0',
        'organization' => 'Example Organization',
        'applications' => [
            [
                'name' => 'Acme Shop',
                'environments' => [
                    [
                        'name' => 'production',
                        'type' => 'production',
                        'horizon_url' => 'https://shop.example.com/horizon',
                        'basic_auth_user' => 'ops',
                        'poll_interval_seconds' => 60,
                        'polling_enabled' => true,
                    ],
                    [
                        'name' => 'staging',
                        'type' => 'staging',
                        'horizon_url' => 'https://staging.shop.example.com/horizon',
                        'basic_auth_user' => null,
                        'poll_interval_seconds' => 30,
                        'polling_enabled' => false,
                    ],
                ],
            ],
            [
                'name' => 'Billing',
                'environments' => [
                    [
                        'name' => 'demo',
                        'type' => 'demo',
                        'horizon_url' => 'https://demo.billing.example.com/horizon',
                        'basic_auth_user' => null,
                        'poll_interval_seconds' => 15,
                        'polling_enabled' => true,
                    ],
                ],
            ],
        ],
        'alert_rules' => [
            ['scope' => 'organization', 'metric' => 'endpoint.unreachable', 'threshold' => 3, 'severity' => 'critical', 'notify_by_email' => true, 'enabled' => true],
            ['scope' => 'organization', 'metric' => 'queue.pending', 'threshold' => 2000, 'severity' => 'warning', 'notify_by_email' => true, 'enabled' => true, 'for_minutes' => 0],
            ['scope' => 'production', 'metric' => 'queue.max_wait', 'threshold' => 90],
        ],
    ]);
});

test('override rows of environments that are gone, and override rows that set nothing, stay out of the file', function () {
    AlertRule::factory()->for($this->team)->inheriting()->create(['scope' => 'retired', 'metric' => AlertRuleMetric::QueuePending, 'threshold' => 10]);
    AlertRule::factory()->for($this->team)->inheriting()->create(['scope' => 'staging', 'metric' => AlertRuleMetric::JobRuntime]);

    expect(collect($this->exporter->export($this->team)['alert_rules'])->pluck('scope')->all())
        ->toBe(['organization', 'organization', 'production']);
});

test('no password, signing secret or credential pasted in a url ever reaches the file', function () {
    $legacy = Environment::factory()->for($this->acme)->create([
        'name' => 'legacy',
        'type' => EnvironmentType::Staging,
        'horizon_url' => 'https://legacy.example.com/horizon',
        'basic_auth_user' => 'ops',
        'basic_auth_password' => 'legacy-password',
    ]);
    DB::table('environments')->where('id', $legacy->id)->update(['horizon_url' => 'https://legacy-owner:legacy-url-secret@Legacy.Example.com/horizon/']);
    NotificationSetting::factory()->for($this->team)->create([
        'webhook_url' => 'https://hooks.example.com/horizon-watch',
        'webhook_secret' => 'whsec-never-exported',
    ]);

    $json = $this->exporter->toJson($this->exporter->export($this->team));

    expect($json)
        ->not->toContain('stored-secret')
        ->not->toContain('legacy-password')
        ->not->toContain('legacy-owner')
        ->not->toContain('legacy-url-secret')
        ->not->toContain('whsec-never-exported')
        ->not->toContain('hooks.example.com')
        ->and(collect(json_decode($json, true)['applications'][0]['environments'])->firstWhere('name', 'legacy')['horizon_url'])
        ->toBe('https://legacy.example.com/horizon');
});

test('the file is readable JSON and its name carries the organization slug and the date', function () {
    $json = $this->exporter->toJson($this->exporter->export($this->team));

    expect($json)->toContain('"horizon_url": "https://shop.example.com/horizon"')
        ->toEndWith("}\n")
        ->and($this->exporter->fileName($this->team))->toBe('horizon-watch-example-organization-2026-10-16.json');
});

test('the names applications share, in any case, are reported once each', function () {
    expect($this->exporter->sharedApplicationNames($this->team))->toBe([]);

    Application::factory()->for($this->team)->create(['name' => 'billing']);
    Application::factory()->for($this->team)->create(['name' => 'BILLING']);
    Application::factory()->for(Team::factory())->create(['name' => 'Acme Shop']);

    expect($this->exporter->sharedApplicationNames($this->team))->toBe(['Billing']);
});
