<?php

use App\Alerts\AlertDelivery;
use App\Alerts\NotificationDelivery;
use App\Alerts\Recipients;
use App\Enums\AlertRuleMetric;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\Locale;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertNotification as DeliveryLog;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Notifications\Alerts\AlertNotification;
use App\Notifications\Alerts\ResolvedNotification;
use App\Notifications\Alerts\TestNotification;
use App\Notifications\Alerts\WarningDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AlertTeam;
use Tests\Support\TraceArguments;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $this->team = Team::factory()->create(['name' => 'Acme']);
    $this->application = Application::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->environment = Environment::factory()->for($this->application)->production()->create();
    $this->admin = AlertTeam::member($this->team, 'admin@example.com', TeamRole::Admin, locale: Locale::It);
    $this->setting = NotificationSetting::factory()->for($this->team)->withWebhook()->create([
        'recipients' => ['ops@example.com'],
    ]);
    $this->alert = Alert::factory()->for($this->environment)->critical()->create();
});

function emailJob(Team $team, ?string $alertId, SentNotificationKind $kind, ?int $userId, ?string $address, array $payload = [], string $locale = 'en'): SendAlertEmail
{
    return new SendAlertEmail($team->id, $alertId, $kind, $userId, $address === null ? null : Recipients::addressKey($address), $locale, $payload);
}

test('the delivery contract is bound to the real implementation', function () {
    expect(app(AlertDelivery::class))->toBeInstanceOf(NotificationDelivery::class);
});

test('an alert email reaches a member in the given language and is logged', function () {
    Notification::fake();

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, $this->admin->id, null, ['repeated' => true], 'it'));

    Notification::assertSentOnDemand(
        AlertNotification::class,
        fn (AlertNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'admin@example.com'
            && $notification->locale === 'it'
            && $notification->repeated
            && $notification->alert->is($this->alert),
    );

    $log = DeliveryLog::query()->sole();

    expect($log->team_id)->toBe($this->team->id)
        ->and($log->alert_id)->toBe($this->alert->id)
        ->and($log->kind)->toBe(SentNotificationKind::CriticalAlert)
        ->and($log->channel)->toBe(NotificationChannel::Mail)
        ->and($log->target)->toBe('admin@example.com')
        ->and($log->status)->toBe(DeliveryStatus::Sent)
        ->and($log->error)->toBeNull()
        ->and($log->sent_at->equalTo(now()))->toBeTrue();
});

test('an extra address is found again by its key and a removed one is skipped', function () {
    Notification::fake();

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::Resolved, null, 'OPS@example.com'));

    Notification::assertSentOnDemand(ResolvedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.com');

    $this->setting->update(['recipients' => ['oncall@example.com']]);
    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::Resolved, null, 'ops@example.com'));

    Notification::assertSentOnDemandTimes(ResolvedNotification::class, 1);
    expect(DeliveryLog::query()->count())->toBe(1);
});

test('a member who opted out or left meanwhile gets nothing, but a test goes to whoever asked', function () {
    Notification::fake();
    $this->admin->update(['alert_emails' => false]);

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, $this->admin->id, null));
    dispatch_sync(emailJob($this->team, null, SentNotificationKind::Test, $this->admin->id, null));

    Notification::assertSentOnDemandTimes(AlertNotification::class, 0);
    Notification::assertSentOnDemand(TestNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'admin@example.com');

    $this->admin->update(['alert_emails' => true]);
    $this->team->members()->detach($this->admin);
    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, $this->admin->id, null));

    Notification::assertSentOnDemandTimes(AlertNotification::class, 0);
    expect(DeliveryLog::query()->pluck('kind')->all())->toBe([SentNotificationKind::Test]);
});

test('an email about a deleted alert or organization is dropped', function () {
    Notification::fake();
    $alertId = $this->alert->id;
    $this->alert->delete();

    dispatch_sync(emailJob($this->team, $alertId, SentNotificationKind::CriticalAlert, $this->admin->id, null));

    $this->team->delete();
    dispatch_sync(emailJob($this->team, null, SentNotificationKind::Test, $this->admin->id, null));

    Notification::assertNothingSent();
    expect(DeliveryLog::query()->count())->toBe(0);
});

test('a digest email renders only the alerts it names, of its organization', function () {
    Notification::fake();
    $warning = Alert::factory()->for($this->environment)->warning()->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    $foreign = Alert::factory()->warning()->create();

    dispatch_sync(emailJob($this->team, null, SentNotificationKind::WarningDigest, $this->admin->id, null, [
        'alertIds' => [$warning->id, $foreign->id],
        'environmentCount' => 1,
    ]));

    Notification::assertSentOnDemand(
        WarningDigestNotification::class,
        fn (WarningDigestNotification $notification) => $notification->alerts->pluck('id')->all() === [$warning->id],
    );

    $log = DeliveryLog::query()->sole();

    expect($log->alert_id)->toBeNull()
        ->and($log->kind)->toBe(SentNotificationKind::WarningDigest)
        ->and($log->environment_count)->toBe(1);

    dispatch_sync(emailJob($this->team, null, SentNotificationKind::WarningDigest, $this->admin->id, null, ['alertIds' => [$foreign->id]]));

    Notification::assertSentOnDemandTimes(WarningDigestNotification::class, 1);
});

test('a mail failure is retried and logged as a bare code, never with the address', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => $port,
        'mail.mailers.smtp.timeout' => 1,
    ]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
        $logged[] = $message->message.json_encode($message->context);
    });

    $job = emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com');

    try {
        app()->call([$job, 'handle']);
        $failure = null;
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->toBe('mail')
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->getTraceAsString().TraceArguments::ofAppFrames($failure))->not->toContain('ops@example.com')
        ->and(DeliveryLog::query()->count())->toBe(0)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);

    $job->failed($failure);

    $log = DeliveryLog::query()->sole();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe(DeliveryError::Mail->value)
        ->and($log->target)->toBe('ops@example.com')
        ->and(implode("\n", $logged))->not->toContain('ops@example.com');
});

test('a mail test goes to the saved addresses and to whoever asked, in their language', function () {
    Queue::fake();
    $this->setting->update(['recipients' => ['ops@example.com', 'Admin@Example.com', 'oncall@example.com']]);
    $viewer = AlertTeam::member($this->team, 'viewer@example.com', TeamRole::Viewer, alertEmails: false);

    $count = app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Mail, $this->admin);

    expect($count)->toBe(3);

    $jobs = Queue::pushed(SendAlertEmail::class);

    expect($jobs->pluck('kind')->unique()->all())->toBe([SentNotificationKind::Test])
        ->and($jobs->pluck('alertId')->unique()->all())->toBe([null])
        ->and($jobs->first()->userId)->toBe($this->admin->id)
        ->and($jobs->first()->locale)->toBe('it')
        ->and($jobs->pluck('userId')->filter()->all())->toBe([$this->admin->id]);

    Queue::fake();

    expect(app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Mail, $viewer))->toBe(4);
});

test('a webhook test goes to the saved url only when there is one', function () {
    Queue::fake();

    expect(app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Webhook, $this->admin))->toBe(1);

    Queue::assertPushed(SendAlertWebhook::class, fn (SendAlertWebhook $job) => $job->kind === SentNotificationKind::Test
        && $job->event === 'test'
        && $job->alertId === null
        && $job->payload['alert'] === null
        && $job->payload['organization']['name'] === 'Acme');

    Queue::fake();
    $this->setting->update(['webhook_url' => null, 'webhook_secret' => null]);

    expect(app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Webhook, $this->admin))->toBe(0);
    Queue::assertNothingPushed();

    NotificationSetting::query()->delete();

    expect(app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Webhook, $this->admin))->toBe(0)
        ->and(app(AlertDelivery::class)->sendTest($this->team, NotificationChannel::Mail, $this->admin))->toBe(1);
});
