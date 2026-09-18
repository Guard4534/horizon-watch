<?php

use App\Data\Applications\ApplicationWizardData;
use App\Data\Applications\EnvironmentFormData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\MemberVisibility;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Dns\Resolver;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\StatusEvaluator;
use App\Notifications\Alerts\AlertMail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Fixtures\Horizon\FakeResolver;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    $this->environment = Environment::factory()->for(Application::factory()->for($this->team))->production()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $this->admin->switchTeam($this->team);
});

test('horizon: the masters kept from a reading follow the configured cap', function (int $cap, int $expected) {
    config(['horizon-watch.horizon.max_masters' => $cap]);
    $this->app->instance(Resolver::class, new FakeResolver(['shop.example.com' => ['203.0.113.10']]));

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $path = rawurldecode(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/horizon/api/'));
        $fixture = match ($path) {
            'stats' => 'stats.json',
            'masters' => 'masters.json',
            'workload' => 'workload.json',
            'jobs/failed' => 'failed.json',
            'jobs/pending' => 'pending.json',
            default => null,
        };

        return $fixture === null
            ? Http::response('[]', 200, ['Content-Type' => 'application/json'])
            : Http::response((string) file_get_contents(base_path('tests/Fixtures/Horizon/'.$fixture)), 200, ['Content-Type' => 'application/json']);
    });

    $reading = app(HorizonReader::class)->read(new HorizonTarget(dashboardUrl: 'https://shop.example.com/horizon', username: null, password: null));

    expect($reading->masters)->toHaveCount($expected);
})->with([
    'default' => [200, 2],
    'lowered' => [1, 1],
]);

test('readings: the failed-rate window follows the configuration', function (int $minutes, int $expected) {
    config(['horizon-watch.readings.failed_rate_minutes' => $minutes]);

    $job = new HorizonFailedJob(name: 'App\\Jobs\\Sync', queue: 'default', exception: 'RuntimeException', attempts: 1, failedAt: CarbonImmutable::now()->subMinutes(30));

    expect(app(StatusEvaluator::class)->failedLastHour([$job]))->toBe($expected);
})->with([
    'default' => [60, 1],
    'narrowed' => [20, 0],
]);

test('readings: a poll interval left out takes the configured default', function () {
    config(['horizon-watch.readings.poll_interval_seconds.default' => 45]);

    $environment = ['name' => 'staging', 'color' => 'staging', 'horizonUrl' => 'https://shop.example.com/horizon'];
    $wizard = ApplicationWizardData::from(['application' => ['name' => 'Shop', 'host' => 'shop.example.com'], 'environments' => [$environment]]);

    expect(EnvironmentFormData::from($environment)->pollIntervalSeconds)->toBe(45)
        ->and($wizard->environments[0]->pollIntervalSeconds)->toBe(45);
});

test('alerts: the queues listed in an alert email follow the configuration', function () {
    $alert = Alert::factory()->for($this->environment)->create([
        'metric' => AlertRuleMetric::WorkersMissing,
        'detail' => ['queues' => ['alpha', 'beta', 'gamma']],
    ]);

    expect(AlertMail::detail($alert))->toContain('gamma');

    config(['horizon-watch.alerts.listed_queues' => 2]);

    expect(AlertMail::detail($alert))->toContain('alpha, beta')->not->toContain('gamma');
});

test('notifications: the recipients cap follows the configuration', function () {
    $save = fn () => $this->actingAs($this->admin)->put(route('alert-settings.update', ['current_team' => $this->team->slug]), [
        'recipients' => ['ops@example.com', 'oncall@example.com'],
        'webhookUrl' => null,
        'quietFrom' => null,
        'quietTo' => null,
        'timezone' => 'UTC',
        'repeatMinutes' => 30,
    ]);

    $save()->assertSessionHasNoErrors();

    config(['horizon-watch.notifications.max_recipients' => 1]);

    $save()->assertSessionHasErrors('recipients');
});

test('notifications: the repeat choices follow the configuration', function () {
    config(['horizon-watch.notifications.repeat_minutes' => [15, 30, 60, 120]]);

    $this->actingAs($this->admin)->put(route('alert-settings.update', ['current_team' => $this->team->slug]), [
        'recipients' => [],
        'webhookUrl' => null,
        'quietFrom' => null,
        'quietTo' => null,
        'timezone' => 'UTC',
        'repeatMinutes' => 120,
    ])->assertSessionHasNoErrors();

    expect(NotificationSetting::query()->find($this->team->id)?->repeat_minutes)->toBe(120);
});

test('notifications: defaults and delivery retries follow the configuration', function () {
    config([
        'horizon-watch.notifications.default_timezone' => 'America/New_York',
        'horizon-watch.notifications.default_repeat_minutes' => 15,
        'horizon-watch.notifications.delivery_tries' => 5,
        'horizon-watch.notifications.delivery_backoff_seconds' => [5, 20, 90],
    ]);

    $setting = new NotificationSetting;
    $job = new SendAlertWebhook($this->team->id, null, SentNotificationKind::Test, 'test', []);

    expect($setting->timezone)->toBe('America/New_York')
        ->and($setting->repeat_minutes)->toBe(15)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([5, 20, 90]);
});

test('pages: the alerts page size follows the configuration', function () {
    Alert::factory()->count(3)->for($this->environment)->resolved()->create();
    config(['horizon-watch.pages.alerts_per_page' => 2]);
    $this->actingAs($this->admin);

    $page = app(MonitoringRepository::class)->alerts($this->team, AlertState::Resolved);

    expect($page->perPage)->toBe(2)
        ->and($page->alerts)->toHaveCount(2)
        ->and($page->total)->toBe(3);
});

test('rate limits: the login limit follows the configuration', function () {
    $user = User::factory()->create();
    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 2);
    $login = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    expect($login()->status())->not->toBe(429);

    config(['horizon-watch.rate_limits.login_per_minute' => 2]);

    $login()->assertTooManyRequests();
});
