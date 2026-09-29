<?php

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\EnvironmentColor;
use App\Enums\Locale;
use App\Enums\MuteDuration;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
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
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00')));

test('an alert rule keeps its scope in lowercase and casts its fields', function () {
    $team = Team::factory()->create();

    $rule = $team->alertRules()->create([
        'scope' => 'Worker-Batch',
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 5000,
        'severity' => AlertSeverity::Critical,
        'notify_email' => false,
        'enabled' => true,
    ])->fresh();

    expect($rule->scope)->toBe('worker-batch')
        ->and($rule->metric)->toBe(AlertRuleMetric::QueuePending)
        ->and($rule->threshold)->toBe(5000.0)
        ->and($rule->severity)->toBe(AlertSeverity::Critical)
        ->and($rule->notify_email)->toBeFalse()
        ->and($rule->enabled)->toBeTrue()
        ->and($rule->team->is($team))->toBeTrue();
});

test('an alert rule inherits every field it leaves empty', function () {
    $rule = AlertRule::factory()->create([
        'threshold' => null,
        'severity' => null,
        'notify_email' => null,
        'enabled' => null,
    ])->fresh();

    expect($rule->threshold)->toBeNull()
        ->and($rule->severity)->toBeNull()
        ->and($rule->notify_email)->toBeNull()
        ->and($rule->enabled)->toBeNull();
});

test('a team has one rule per scope and metric', function () {
    $team = Team::factory()->create();

    AlertRule::factory()->for($team)->create(['scope' => 'production', 'metric' => AlertRuleMetric::QueuePending]);

    expect(fn () => DB::transaction(fn () => AlertRule::factory()->for($team)->create(['scope' => 'Production', 'metric' => AlertRuleMetric::QueuePending])))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('notification settings default to no recipients, no webhook and the configured time zone and repetition', function () {
    $team = Team::factory()->create();

    $settings = $team->notificationSetting()->create([]);

    expect($settings->team_id)->toBe($team->id)
        ->and($settings->recipients)->toBe([])
        ->and($settings->timezone)->toBe(NotificationSetting::defaultTimezone())
        ->and($settings->repeat_minutes)->toBe(NotificationSetting::defaultRepeatMinutes());

    $fresh = $settings->fresh();

    expect($fresh->recipients)->toBe([])
        ->and($fresh->webhook_url)->toBeNull()
        ->and($fresh->webhook_secret)->toBeNull()
        ->and($fresh->quiet_from)->toBeNull()
        ->and($fresh->timezone)->toBe(NotificationSetting::defaultTimezone())
        ->and($fresh->repeat_minutes)->toBe(NotificationSetting::defaultRepeatMinutes())
        ->and($team->fresh()->notificationSetting->is($fresh))->toBeTrue();
});

test('the webhook secret is encrypted at rest and never serialized', function () {
    $settings = NotificationSetting::factory()->create([
        'webhook_url' => 'https://hooks.example.com/horizon',
        'webhook_secret' => 'a-webhook-secret-value',
        'recipients' => ['ops@example.com'],
        'repeat_minutes' => null,
    ]);

    $raw = DB::table('notification_settings')->where('team_id', $settings->team_id)->value('webhook_secret');

    expect($raw)->not->toContain('a-webhook-secret-value')
        ->and($settings->fresh()->webhook_secret)->toBe('a-webhook-secret-value')
        ->and($settings->fresh()->repeat_minutes)->toBeNull()
        ->and($settings->fresh()->recipients)->toBe(['ops@example.com'])
        ->and(json_encode($settings->fresh()))->not->toContain('a-webhook-secret-value')
        ->and($settings->fresh()->toArray())->not->toHaveKey('webhook_secret');
});

test('an alert copies its environment and casts its columns', function () {
    $environment = Environment::factory()->create();

    $alert = Alert::factory()->for($environment)->create([
        'metric' => AlertRuleMetric::QueueMaxWait,
        'detail' => ['waitSeconds' => 90],
        'value' => 90,
        'threshold' => 60,
    ])->fresh();

    expect($alert->id)->toBeString()->toHaveLength(36)
        ->and($alert->team_id)->toBe($environment->team_id)
        ->and($alert->metric)->toBe(AlertRuleMetric::QueueMaxWait)
        ->and($alert->severity)->toBeInstanceOf(AlertSeverity::class)
        ->and($alert->environment_color)->toBe(EnvironmentColor::Prod)
        ->and($alert->application_name)->toBe($environment->application->name)
        ->and($alert->environment_name)->toBe('production')
        ->and($alert->detail)->toBe(['waitSeconds' => 90])
        ->and($alert->value)->toBe(90.0)
        ->and($alert->threshold)->toBe(60.0)
        ->and($alert->opened_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($alert->last_seen_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($alert->muted_indefinitely)->toBeFalse()
        ->and($alert->notified)->toBeFalse()
        ->and($alert->environment->is($environment))->toBeTrue()
        ->and($alert->team->is($environment->application->team))->toBeTrue()
        ->and($environment->application->team->alerts()->sole()->is($alert))->toBeTrue();
});

test('an alert records who muted and who handled it', function () {
    $user = User::factory()->create();

    $alert = Alert::factory()->create([
        'muted_by' => $user->id,
        'handled_by' => $user->id,
        'handled_at' => now(),
    ])->fresh();

    expect($alert->mutedBy->is($user))->toBeTrue()
        ->and($alert->handledBy->is($user))->toBeTrue()
        ->and($alert->handled_at)->toBeInstanceOf(CarbonImmutable::class);

    $user->delete();

    expect($alert->fresh()->muted_by)->toBeNull()
        ->and($alert->fresh()->handled_by)->toBeNull();
});

test('only one alert per environment and metric is open at a time', function () {
    $environment = Environment::factory()->create();

    Alert::factory()->for($environment)->resolved()->create(['metric' => AlertRuleMetric::QueuePending]);
    Alert::factory()->for($environment)->resolved()->create(['metric' => AlertRuleMetric::QueuePending]);
    Alert::factory()->for($environment)->create(['metric' => AlertRuleMetric::QueuePending]);
    Alert::factory()->for($environment)->create(['metric' => AlertRuleMetric::QueueMaxWait]);

    expect(fn () => DB::transaction(fn () => Alert::factory()->for($environment)->create(['metric' => AlertRuleMetric::QueuePending])))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Alert::query()->count())->toBe(4);
});

test('an alert outlives its environment and loses only the link', function () {
    $environment = Environment::factory()->create();
    $alert = Alert::factory()->for($environment)->resolved()->create();

    $environment->delete();

    $fresh = $alert->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->environment_id)->toBeNull()
        ->and($fresh->environment_name)->toBe('production');
});

test('the state of an alert follows resolution and mute at a given time', function (array $attributes, string $at, AlertState $state) {
    $alert = Alert::factory()->make($attributes);

    expect($alert->state(CarbonImmutable::parse($at)))->toBe($state);
})->with([
    'open' => [[], '2026-09-18 10:00:00', AlertState::Open],
    'muted for a while' => [['muted_until' => '2026-09-18 11:00:00'], '2026-09-18 10:59:59', AlertState::Muted],
    'a mute that just ended' => [['muted_until' => '2026-09-18 11:00:00'], '2026-09-18 11:00:00', AlertState::Open],
    'muted until resolved' => [['muted_indefinitely' => true], '2026-09-19 10:00:00', AlertState::Muted],
    'resolved while muted' => [['muted_indefinitely' => true, 'resolved_at' => '2026-09-18 09:00:00'], '2026-09-18 10:00:00', AlertState::Resolved],
    'resolved' => [['resolved_at' => '2026-09-18 09:00:00'], '2026-09-18 10:00:00', AlertState::Resolved],
]);

test('the alert scopes split open, resolved, muted and unmuted alerts', function () {
    $now = CarbonImmutable::now();

    $open = Alert::factory()->create();
    $mutedForAWhile = Alert::factory()->create(['muted_until' => $now->addHour()]);
    $muteEnded = Alert::factory()->create(['muted_until' => $now]);
    $mutedUntilResolved = Alert::factory()->create(['muted_indefinitely' => true]);
    $resolved = Alert::factory()->resolved()->create();

    $ids = fn ($query) => $query->pluck('id')->sort()->values()->all();
    $sorted = fn (Alert ...$alerts) => collect($alerts)->pluck('id')->sort()->values()->all();

    expect($ids(Alert::query()->open()))->toBe($sorted($open, $mutedForAWhile, $muteEnded, $mutedUntilResolved))
        ->and($ids(Alert::query()->whereNotNull('resolved_at')))->toBe($sorted($resolved))
        ->and($ids(Alert::query()->open()->mutedAt($now)))->toBe($sorted($mutedForAWhile, $mutedUntilResolved))
        ->and($ids(Alert::query()->open()->unmutedAt($now)))->toBe($sorted($open, $muteEnded));
});

test('the mute scopes agree with the state of an alert for a time given in another zone', function () {
    $mutedForAWhile = Alert::factory()->create(['muted_until' => CarbonImmutable::parse('2026-09-18 10:30:00', 'UTC')]);
    $muteEnded = Alert::factory()->create(['muted_until' => CarbonImmutable::parse('2026-09-18 09:30:00', 'UTC')]);
    $now = CarbonImmutable::parse('2026-09-18 12:00:00', 'Europe/Rome');

    $ids = fn ($query) => $query->pluck('id')->all();

    expect($mutedForAWhile->fresh()->state($now))->toBe(AlertState::Muted)
        ->and($muteEnded->fresh()->state($now))->toBe(AlertState::Open)
        ->and($ids(Alert::query()->mutedAt($now)))->toBe([$mutedForAWhile->id])
        ->and($ids(Alert::query()->unmutedAt($now)))->toBe([$muteEnded->id])
        ->and($now->getTimezone()->getName())->toBe('Europe/Rome');
});

test('an alert of a deleted environment can be made without one', function () {
    $team = Team::factory()->create();

    $alert = Alert::factory()->withoutEnvironment()->create([
        'team_id' => $team->id,
        'application_name' => 'Billing',
        'environment_name' => 'production',
        'environment_color' => EnvironmentColor::Prod,
    ])->fresh();

    expect($alert->environment_id)->toBeNull()
        ->and($alert->team_id)->toBe($team->id)
        ->and($alert->application_name)->toBe('Billing')
        ->and($alert->environment_name)->toBe('production')
        ->and($alert->environment_color)->toBe(EnvironmentColor::Prod)
        ->and($alert->threshold)->toBe(AlertRuleMetric::QueuePending->defaultThreshold())
        ->and(Environment::query()->count())->toBe(0);
});

test('a notification log row belongs to its alert and goes with it', function () {
    $alert = Alert::factory()->create();

    $logged = AlertNotification::factory()->for($alert)->create([
        'kind' => SentNotificationKind::CriticalAlert,
        'channel' => NotificationChannel::Mail,
        'target' => 'ops@example.com',
        'status' => DeliveryStatus::Sent,
    ])->fresh();

    $digest = AlertNotification::factory()->create([
        'team_id' => $alert->team_id,
        'alert_id' => null,
        'kind' => SentNotificationKind::Test,
        'channel' => NotificationChannel::Webhook,
        'target' => 'hooks.example.com',
        'status' => DeliveryStatus::Failed,
        'error' => DeliveryError::Timeout,
    ]);

    expect($logged->alert_id)->toBe($alert->id)
        ->and($logged->kind)->toBe(SentNotificationKind::CriticalAlert)
        ->and($logged->channel)->toBe(NotificationChannel::Mail)
        ->and($logged->status)->toBe(DeliveryStatus::Sent)
        ->and($logged->sent_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($logged->alert->is($alert))->toBeTrue()
        ->and(AlertNotification::query()->where('alert_id', $alert->id)->sole()->is($logged))->toBeTrue()
        ->and($alert->team->alertNotifications()->count())->toBe(2)
        ->and($digest->fresh()->alert_id)->toBeNull();

    $alert->delete();

    expect(AlertNotification::query()->pluck('id')->all())->toBe([$digest->id]);
});

test('a hard-deleted team takes its rules, settings, alerts and log with it', function () {
    $team = Team::factory()->create();
    $alert = Alert::factory()->for(Environment::factory()->for(Application::factory()->for($team)))->create();
    AlertRule::factory()->for($team)->create();
    NotificationSetting::factory()->for($team)->create();
    AlertNotification::factory()->for($alert)->create();

    expect($alert->team_id)->toBe($team->id)
        ->and($team->alertRules()->count())->toBe(1);

    $team->forceDelete();

    expect(Alert::query()->count())->toBe(0)
        ->and(AlertRule::query()->count())->toBe(0)
        ->and(NotificationSetting::query()->count())->toBe(0)
        ->and(AlertNotification::query()->count())->toBe(0);
});

test('a user does not receive alert emails until they opt in', function () {
    $user = User::factory()->create();

    expect($user->fresh()->alert_emails)->toBeFalse();

    $user->update(['alert_emails' => true]);

    expect($user->fresh()->alert_emails)->toBeTrue();
});

test('a user is addressed in the language they chose', function () {
    $user = User::factory()->create(['locale' => Locale::It]);

    expect($user)->toBeInstanceOf(HasLocalePreference::class)
        ->and($user->preferredLocale())->toBe('it')
        ->and(User::factory()->create(['locale' => null])->preferredLocale())->toBeNull();
});

test('an environment state remembers since when its status holds', function () {
    $state = EnvironmentState::factory()->create(['status_since' => '2026-09-18 09:30:00'])->fresh();

    expect($state->status_since)->toBeInstanceOf(CarbonImmutable::class)
        ->and($state->status_since->toDateTimeString())->toBe('2026-09-18 09:30:00');
});

test('mute durations know their length', function () {
    expect(MuteDuration::OneHour->minutes())->toBe(60)
        ->and(MuteDuration::FourHours->minutes())->toBe(240)
        ->and(MuteDuration::OneDay->minutes())->toBe(1440)
        ->and(MuteDuration::UntilResolved->minutes())->toBeNull()
        ->and(array_map(fn (MuteDuration $duration) => $duration->value, MuteDuration::cases()))->toBe(['1h', '4h', '24h', 'resolved'])
        ->and(array_map(fn (DeliveryStatus $status) => $status->value, DeliveryStatus::cases()))->toBe(['sent', 'failed'])
        ->and(SentNotificationKind::Test->value)->toBe('test');
});

test('every metric has validation bounds and knows whether it measures a state', function (AlertRuleMetric $metric, float $maximum, bool $state) {
    expect($metric->minimumThreshold())->toBe(1.0)
        ->and($metric->maximumThreshold())->toBe($maximum)
        ->and($metric->isStateRule())->toBe($state)
        ->and($metric->defaultThreshold())->toBeGreaterThanOrEqual($metric->minimumThreshold())
        ->and($metric->defaultThreshold())->toBeLessThanOrEqual($metric->maximumThreshold());
})->with([
    [AlertRuleMetric::HorizonMasterInactive, 1440.0, true],
    [AlertRuleMetric::EndpointUnreachable, 1440.0, true],
    [AlertRuleMetric::HorizonPaused, 1440.0, true],
    [AlertRuleMetric::QueuePending, 2147483647.0, false],
    [AlertRuleMetric::QueueMaxWait, 86400.0, false],
    [AlertRuleMetric::JobRuntime, 86400.0, false],
    [AlertRuleMetric::JobsFailedPerHour, 49.0, false],
    [AlertRuleMetric::WorkersMissing, 1000.0, false],
]);
