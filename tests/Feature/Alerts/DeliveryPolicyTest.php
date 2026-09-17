<?php

use App\Alerts\DeliveryPolicy;
use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Alerts\Payloads\WebhookPayload;
use App\Alerts\Recipients;
use App\Enums\AlertRuleMetric;
use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Jobs\PollEnvironmentJob;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Listeners\Alerts\SendOnOpen;
use App\Listeners\Alerts\SendOnResolve;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Alerts\AlertNotification;
use App\Notifications\Alerts\ResolvedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AlertTeam;

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC');
    $this->travelTo($this->now);

    $this->team = Team::factory()->create(['name' => 'Acme']);
    $application = Application::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->production = Environment::factory()->for($application)->production()->create();
    $this->staging = Environment::factory()->for($application)->staging()->create();

    $this->admin = AlertTeam::member($this->team, 'admin@example.com', TeamRole::Admin, locale: Locale::It);
    $this->dev = AlertTeam::member($this->team, 'dev@example.com', visibility: MemberVisibility::NonProduction);

    $this->setting = NotificationSetting::factory()->for($this->team)->withWebhook()->create([
        'recipients' => ['ops@example.com'],
    ]);

    Queue::fake();
});

function deliveryPolicy(): DeliveryPolicy
{
    app()->forgetScopedInstances();

    return app(DeliveryPolicy::class);
}

/**
 * @return Collection<int, array{kind: SentNotificationKind, to: string, locale: string, alert: ?string, payload: array<string, mixed>}>
 */
function queuedEmails(): Collection
{
    return Queue::pushed(SendAlertEmail::class)->map(fn (SendAlertEmail $job) => [
        'kind' => $job->kind,
        'to' => $job->userId !== null
            ? User::query()->findOrFail($job->userId)->email
            : app(Recipients::class)->extraAddress(Team::query()->findOrFail($job->teamId), (string) $job->addressKey),
        'locale' => $job->locale,
        'alert' => $job->alertId,
        'payload' => $job->payload,
    ])->values();
}

/**
 * @return Collection<int, SendAlertWebhook>
 */
function queuedWebhooks(): Collection
{
    return Queue::pushed(SendAlertWebhook::class)->values();
}

function criticalAlert(Environment $environment, array $attributes = []): Alert
{
    return Alert::factory()->for($environment)->critical()->create([
        'metric' => AlertRuleMetric::HorizonMasterInactive,
        'opened_at' => now()->subMinutes(6),
        ...$attributes,
    ]);
}

function warningAlert(Environment $environment, array $attributes = []): Alert
{
    return Alert::factory()->for($environment)->warning()->create([
        'metric' => AlertRuleMetric::QueuePending,
        'opened_at' => now()->subMinutes(6),
        ...$attributes,
    ]);
}

test('the listeners are wired to the alert events', function () {
    Event::fake();

    Event::assertListening(AlertOpened::class, SendOnOpen::class);
    Event::assertListening(AlertResolved::class, SendOnResolve::class);
});

test('a new critical alert is sent at once by email and webhook', function () {
    $alert = criticalAlert($this->production);

    deliveryPolicy()->onOpened($alert);

    expect(queuedEmails()->map(fn ($email) => [$email['to'], $email['locale'], $email['kind'], $email['alert'], $email['payload']])->all())->toBe([
        ['admin@example.com', 'it', SentNotificationKind::CriticalAlert, $alert->id, ['repeated' => false]],
        ['ops@example.com', 'en', SentNotificationKind::CriticalAlert, $alert->id, ['repeated' => false]],
    ]);

    [$webhook] = queuedWebhooks()->all();

    expect(queuedWebhooks())->toHaveCount(1)
        ->and($webhook->teamId)->toBe($this->team->id)
        ->and($webhook->alertId)->toBe($alert->id)
        ->and($webhook->kind)->toBe(SentNotificationKind::WebhookDelivery)
        ->and($webhook->event)->toBe('alert.opened')
        ->and($webhook->payload['alert']['id'])->toBe($alert->id)
        ->and($webhook->payload['alert']['rule'])->toBe('horizon.master_inactive')
        ->and($webhook->payload['alert']['severity'])->toBe('critical')
        ->and($webhook->payload['organization'])->toBe(['name' => 'Acme', 'slug' => $this->team->slug]);

    $alert->refresh();

    expect($alert->notified)->toBeTrue()
        ->and($alert->last_notified_at?->equalTo($this->now))->toBeTrue();
});

test('the job queue never carries an address, the webhook url or its secret', function () {
    deliveryPolicy()->onOpened(criticalAlert($this->production));

    $serialized = serialize(Queue::pushedJobs());

    expect($serialized)->not->toContain('@example.com')
        ->not->toContain('hooks.example.com')
        ->not->toContain((string) $this->setting->webhook_secret);
});

test('a new critical alert is sent even inside quiet hours', function () {
    $this->setting->update(['quiet_from' => '00:00', 'quiet_to' => '23:59', 'timezone' => 'UTC']);

    deliveryPolicy()->onOpened(criticalAlert($this->production));

    expect(queuedEmails())->toHaveCount(2)
        ->and(queuedWebhooks())->toHaveCount(1);
});

test('nothing is sent at once for a warning, a muted, handled, resolved or already notified alert', function (string $case) {
    if ($case === 'collection paused') {
        $this->production->update(['polling_enabled' => false]);
    }

    $alert = match ($case) {
        'warning' => warningAlert($this->production),
        'muted' => criticalAlert($this->production, ['muted_until' => now()->addHour()]),
        'muted until resolved' => criticalAlert($this->production, ['muted_indefinitely' => true]),
        'handled' => criticalAlert($this->production, ['handled_at' => now()]),
        'resolved' => criticalAlert($this->production, ['resolved_at' => now()]),
        'already notified' => criticalAlert($this->production, ['notified' => true, 'last_notified_at' => now()->subMinute()]),
        'collection paused' => criticalAlert($this->production),
    };

    deliveryPolicy()->onOpened($alert->fresh());

    expect(queuedEmails())->toHaveCount(0)
        ->and(queuedWebhooks())->toHaveCount(0);
})->with(['warning', 'muted', 'muted until resolved', 'handled', 'resolved', 'already notified', 'collection paused']);

test('an opening handled twice is sent once', function () {
    $alert = criticalAlert($this->production);

    deliveryPolicy()->onOpened($alert);
    deliveryPolicy()->onOpened($alert);

    expect(queuedEmails())->toHaveCount(2)
        ->and(queuedWebhooks())->toHaveCount(1);
});

test('email follows the effective rule while the webhook gets everything', function () {
    AlertRule::factory()->for($this->team)->forScope('production')->inheriting()->create([
        'metric' => AlertRuleMetric::HorizonMasterInactive,
        'notify_email' => false,
    ]);

    deliveryPolicy()->onOpened(criticalAlert($this->production));

    expect(queuedEmails())->toHaveCount(0)
        ->and(queuedWebhooks())->toHaveCount(1);
});

test('an alert without any target is not marked notified and is announced once a target exists', function () {
    $this->setting->update(['recipients' => [], 'webhook_url' => null, 'webhook_secret' => null]);
    $this->admin->update(['alert_emails' => false]);
    $alert = criticalAlert($this->production);

    deliveryPolicy()->onOpened($alert);
    deliveryPolicy()->repeatDue($this->now);

    expect(Queue::pushedJobs())->toBe([])
        ->and($alert->refresh()->notified)->toBeFalse();

    $this->setting->update(['webhook_url' => 'https://hooks.example.com/horizon', 'webhook_secret' => 'a-new-secret']);
    $this->travel(1)->minutes();

    deliveryPolicy()->repeatDue(CarbonImmutable::now());

    expect(queuedWebhooks())->toHaveCount(1)
        ->and(queuedWebhooks()->first()->event)->toBe('alert.opened')
        ->and($alert->refresh()->notified)->toBeTrue();
});

test('a critical alert raised by a later reading is announced by the scheduler', function () {
    $alert = warningAlert($this->production);
    deliveryPolicy()->onOpened($alert);

    $alert->update(['severity' => 'critical']);
    deliveryPolicy()->repeatDue($this->now);

    expect(queuedEmails()->pluck('payload')->all())->toBe([['repeated' => false], ['repeated' => false]])
        ->and(queuedWebhooks()->first()->event)->toBe('alert.opened');
});

test('a critical alert repeats at the organization cadence', function (?int $minutes, bool $hasSettings, ?int $repeatsAfter) {
    if (! $hasSettings) {
        $this->setting->delete();
    } else {
        $this->setting->update(['repeat_minutes' => $minutes]);
    }

    $alert = criticalAlert($this->production, ['notified' => true, 'last_notified_at' => $this->now]);

    foreach ([14, 15, 29, 30, 59, 60, 61, 600] as $after) {
        Queue::fake();
        $this->travelTo($this->now->addMinutes($after));
        deliveryPolicy()->repeatDue(CarbonImmutable::now());

        $due = $repeatsAfter !== null && $after >= $repeatsAfter;

        expect(Queue::pushed(SendAlertEmail::class)->count() + Queue::pushed(SendAlertWebhook::class)->count() > 0)
            ->toBe($due, "after {$after} minutes");

        if ($due) {
            $alert->refresh()->update(['last_notified_at' => $this->now]);
        }
    }
})->with([
    'never' => [null, true, null],
    'every 15 minutes' => [15, true, 15],
    'every 30 minutes' => [30, true, 30],
    'every 60 minutes' => [60, true, 60],
    'no settings: every 30 minutes' => [null, false, 30],
]);

test('a repetition says so and moves the clock', function () {
    $alert = criticalAlert($this->production, ['notified' => true, 'last_notified_at' => $this->now->subMinutes(30)]);

    deliveryPolicy()->repeatDue($this->now);
    deliveryPolicy()->repeatDue($this->now);

    expect(queuedEmails()->pluck('payload')->all())->toBe([['repeated' => true], ['repeated' => true]])
        ->and(queuedEmails()->pluck('kind')->unique()->all())->toBe([SentNotificationKind::CriticalAlert])
        ->and(queuedWebhooks())->toHaveCount(1)
        ->and(queuedWebhooks()->first()->event)->toBe('alert.repeated')
        ->and($alert->refresh()->last_notified_at?->equalTo($this->now))->toBeTrue();
});

test('handling, muting or resolving stops the repetition; an expired mute resumes it', function () {
    $overdue = ['notified' => true, 'last_notified_at' => $this->now->subHour()];
    criticalAlert($this->production, [...$overdue, 'handled_at' => $this->now->subMinutes(5)]);
    criticalAlert($this->production, [...$overdue, 'metric' => AlertRuleMetric::EndpointUnreachable, 'muted_until' => $this->now->addMinute()]);
    criticalAlert($this->staging, [...$overdue, 'muted_indefinitely' => true]);
    criticalAlert($this->staging, [...$overdue, 'metric' => AlertRuleMetric::EndpointUnreachable, 'resolved_at' => $this->now->subMinute()]);

    deliveryPolicy()->repeatDue($this->now);

    expect(Queue::pushedJobs())->toBe([]);

    $this->travelTo($this->now->addMinute());
    deliveryPolicy()->repeatDue(CarbonImmutable::now());

    expect(queuedWebhooks())->toHaveCount(1)
        ->and(queuedWebhooks()->first()->payload['alert']['rule'])->toBe('endpoint.unreachable')
        ->and(queuedWebhooks()->first()->event)->toBe('alert.repeated');
});

test('a repetition of a deleted organization or a paused collection is not sent', function () {
    $overdue = ['notified' => true, 'last_notified_at' => $this->now->subHour()];
    criticalAlert($this->production, $overdue);
    $this->production->update(['polling_enabled' => false]);

    $other = Team::factory()->create();
    $otherEnvironment = Environment::factory()->for(Application::factory()->for($other))->create();
    NotificationSetting::factory()->for($other)->withWebhook()->create();
    criticalAlert($otherEnvironment, $overdue);
    $other->delete();

    deliveryPolicy()->repeatDue($this->now);

    expect(Queue::pushedJobs())->toBe([]);
});

test('a notified critical alert announces its resolution', function () {
    $alert = criticalAlert($this->production, ['notified' => true, 'last_notified_at' => $this->now->subMinutes(5), 'resolved_at' => $this->now]);

    deliveryPolicy()->onResolved($alert);

    expect(queuedEmails()->map(fn ($email) => [$email['to'], $email['kind']])->all())->toBe([
        ['admin@example.com', SentNotificationKind::Resolved],
        ['ops@example.com', SentNotificationKind::Resolved],
    ])
        ->and(queuedWebhooks())->toHaveCount(1)
        ->and(queuedWebhooks()->first()->kind)->toBe(SentNotificationKind::WebhookDelivery)
        ->and(queuedWebhooks()->first()->event)->toBe('alert.resolved')
        ->and(queuedWebhooks()->first()->payload['alert']['resolved_at'])->toBe('2026-09-17T12:00:00Z');
});

test('a resolution is announced only for a notified, unmuted critical alert', function (array $attributes, bool $announced) {
    $alert = criticalAlert($this->production, [
        'notified' => true,
        'last_notified_at' => $this->now->subMinutes(5),
        'resolved_at' => $this->now->subMinute(),
        ...$attributes,
    ]);

    deliveryPolicy()->onResolved($alert);

    expect(queuedWebhooks()->count())->toBe($announced ? 1 : 0)
        ->and(queuedEmails()->count())->toBe($announced ? 2 : 0);
})->with([
    'handled' => [['handled_at' => now()], true],
    'mute expired before the resolution' => [['muted_until' => CarbonImmutable::parse('2026-09-17 11:58:00', 'UTC')], true],
    'never notified' => [['notified' => false, 'last_notified_at' => null], false],
    'warning' => [['severity' => 'warning'], false],
    'muted at the resolution' => [['muted_until' => CarbonImmutable::parse('2026-09-17 11:59:30', 'UTC')], false],
    'muted until resolved' => [['muted_indefinitely' => true], false],
    'still open' => [['resolved_at' => null], false],
]);

test('the digest sends each recipient the warnings they see and the webhook all of them', function () {
    $production = warningAlert($this->production);
    $staging = warningAlert($this->staging, ['metric' => AlertRuleMetric::QueueMaxWait]);
    $resolved = warningAlert($this->staging, ['metric' => AlertRuleMetric::JobsFailedPerHour, 'resolved_at' => $this->now->subMinutes(2)]);

    deliveryPolicy()->digestDue($this->now);

    $emails = queuedEmails()->keyBy('to');

    expect($emails->keys()->all())->toBe(['admin@example.com', 'dev@example.com', 'ops@example.com'])
        ->and($emails->pluck('kind')->unique()->all())->toBe([SentNotificationKind::WarningDigest])
        ->and($emails->pluck('alert')->unique()->all())->toBe([null])
        ->and($emails['admin@example.com']['payload'])->toBe(['alertIds' => [$production->id, $staging->id, $resolved->id], 'environmentCount' => 2])
        ->and($emails['admin@example.com']['locale'])->toBe('it')
        ->and($emails['dev@example.com']['payload'])->toBe(['alertIds' => [$staging->id, $resolved->id], 'environmentCount' => 1])
        ->and($emails['ops@example.com']['payload']['alertIds'])->toHaveCount(3);

    [$webhook] = queuedWebhooks()->all();

    expect(queuedWebhooks())->toHaveCount(1)
        ->and($webhook->kind)->toBe(SentNotificationKind::WarningDigest)
        ->and($webhook->alertId)->toBeNull()
        ->and($webhook->event)->toBe(WebhookPayload::DIGEST)
        ->and($webhook->environmentCount)->toBe(2)
        ->and(array_column($webhook->payload['alerts'], 'id'))->toBe([$production->id, $staging->id, $resolved->id])
        ->and(Alert::query()->whereNull('digested_at')->count())->toBe(0);

    Queue::fake();
    deliveryPolicy()->digestDue($this->now->addMinutes(15));

    expect(Queue::pushedJobs())->toBe([]);
});

test('an empty period sends no digest', function () {
    warningAlert($this->production, ['digested_at' => $this->now->subMinutes(10)]);
    warningAlert($this->production, ['metric' => AlertRuleMetric::JobRuntime, 'resolved_at' => $this->now->subDays(2)]);
    criticalAlert($this->production);
    warningAlert($this->production, ['metric' => AlertRuleMetric::QueueMaxWait, 'muted_until' => $this->now->addHour()]);
    warningAlert($this->production, ['metric' => AlertRuleMetric::WorkersMissing, 'muted_indefinitely' => true, 'resolved_at' => $this->now->subMinute()]);

    deliveryPolicy()->digestDue($this->now);

    expect(Queue::pushedJobs())->toBe([]);
});

test('a warning already digested comes back once when it resolves', function () {
    $alert = warningAlert($this->production, ['digested_at' => $this->now->subMinutes(15)]);
    $alert->update(['resolved_at' => $this->now->subMinutes(3)]);

    deliveryPolicy()->digestDue($this->now);

    expect(queuedWebhooks())->toHaveCount(1)
        ->and(queuedWebhooks()->first()->payload['alerts'][0]['resolved_at'])->toBe('2026-09-17T11:57:00Z');

    Queue::fake();
    deliveryPolicy()->digestDue($this->now->addMinutes(15));

    expect(Queue::pushedJobs())->toBe([]);
});

test('warnings with email off stay out of the emails but reach the webhook', function () {
    AlertRule::factory()->for($this->team)->inheriting()->create(['metric' => AlertRuleMetric::QueuePending, 'notify_email' => false]);
    warningAlert($this->production);

    deliveryPolicy()->digestDue($this->now);

    expect(queuedEmails())->toHaveCount(0)
        ->and(queuedWebhooks())->toHaveCount(1);
});

test('quiet hours hold the digest back until the window ends', function (string $timezone, string $held, string $sent) {
    $this->setting->update(['quiet_from' => '23:00', 'quiet_to' => '07:00:00', 'timezone' => $timezone]);
    $alert = warningAlert($this->production, ['opened_at' => CarbonImmutable::parse($held, 'UTC')->subHour()]);

    deliveryPolicy()->digestDue(CarbonImmutable::parse($held, 'UTC'));

    expect(Queue::pushedJobs())->toBe([])
        ->and($alert->refresh()->digested_at)->toBeNull();

    deliveryPolicy()->digestDue(CarbonImmutable::parse($sent, 'UTC'));

    expect(queuedEmails()->pluck('to')->all())->toBe(['admin@example.com', 'ops@example.com'])
        ->and(queuedWebhooks())->toHaveCount(1)
        ->and($alert->refresh()->digested_at)->not->toBeNull();
})->with([
    'rome, just before 07:00 local' => ['Europe/Rome', '2026-09-17 04:45:00', '2026-09-17 05:00:00'],
    'new york, across midnight' => ['America/New_York', '2026-09-18 03:45:00', '2026-09-18 11:00:00'],
]);

test('the digest of each organization is separate', function () {
    warningAlert($this->production);
    $other = Team::factory()->create();
    $otherEnvironment = Environment::factory()->for(Application::factory()->for($other))->create();
    NotificationSetting::factory()->for($other)->withWebhook('https://hooks.example.net/other')->create();
    warningAlert($otherEnvironment);

    deliveryPolicy()->digestDue($this->now);

    expect(queuedWebhooks()->pluck('teamId')->sort()->values()->all())->toBe([$this->team->id, $other->id])
        ->and(queuedWebhooks()->map(fn ($job) => count($job->payload['alerts']))->all())->toBe([1, 1]);
});

test('the events reach the recipients through the listeners and the jobs', function () {
    Queue::fake([PollEnvironmentJob::class]);
    Notification::fake();
    $alert = criticalAlert($this->production);
    $this->setting->update(['webhook_url' => null, 'webhook_secret' => null]);

    AlertOpened::dispatch($alert->id);

    Notification::assertSentOnDemandTimes(AlertNotification::class, 2);
    Notification::assertSentOnDemand(
        AlertNotification::class,
        fn (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'admin@example.com'
            && $notification->locale === 'it'
            && $notification->alert->is($alert)
            && ! $notification->repeated,
    );

    $alert->update(['resolved_at' => now()]);
    AlertResolved::dispatch($alert->id);

    Notification::assertSentOnDemandTimes(ResolvedNotification::class, 2);

    AlertOpened::dispatch('00000000-0000-0000-0000-000000000000');

    Notification::assertSentOnDemandTimes(AlertNotification::class, 2);
});
