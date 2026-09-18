<?php

use App\Alerts\AlertDelivery;
use App\Alerts\NotificationDelivery;
use App\Alerts\Payloads\WebhookPayload;
use App\Alerts\Recipients;
use App\Enums\AlertRuleMetric;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Externals\Horizon\Dns\Resolver;
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
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Horizon\FakeResolver;
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

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalRepeated, $this->admin->id, null, [], 'it'));

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
        ->and($log->kind)->toBe(SentNotificationKind::CriticalRepeated)
        ->and($log->channel)->toBe(NotificationChannel::Mail)
        ->and($log->target)->toBe('admin@example.com')
        ->and($log->status)->toBe(DeliveryStatus::Sent)
        ->and($log->error)->toBeNull()
        ->and($log->sent_at->equalTo(now()))->toBeTrue();
});

test('an extra address is found again by its key and a removed one is skipped', function () {
    Notification::fake();
    $this->alert->update(['resolved_at' => now()]);

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

function closedSmtpPort(): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => $port,
        'mail.mailers.smtp.timeout' => 1,
    ]);
}

function workOneEmail(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0]);
}

test('a mail failure is retried by the worker with its backoff, then logged once as failed without the address', function () {
    closedSmtpPort();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
        $logged[] = $message->message.json_encode($message->context);
    });

    dispatch(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com')->onConnection('database'));

    workOneEmail();
    workOneEmail();

    expect(DeliveryLog::query()->count())->toBe(0)
        ->and(DB::table('jobs')->value('attempts'))->toBe(1);

    $this->travel(10)->seconds();
    workOneEmail();

    expect(DeliveryLog::query()->count())->toBe(0)
        ->and(DB::table('jobs')->value('attempts'))->toBe(2);

    $this->travel(59)->seconds();
    workOneEmail();

    expect(DB::table('jobs')->value('attempts'))->toBe(2);

    $this->travel(1)->seconds();
    workOneEmail();

    $log = DeliveryLog::query()->sole();
    $failedJob = DB::table('failed_jobs')->sole();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe(DeliveryError::Mail->value)
        ->and($log->target)->toBe('ops@example.com')
        ->and($log->delivery_id)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and((string) $failedJob->exception)->toStartWith('RuntimeException: mail')
        ->and(json_encode($failedJob))->not->toContain('ops@example.com')
        ->and(implode("\n", $logged))->not->toContain('ops@example.com');
});

test('a failing delivery log leaks no address and sends nothing, and a delivered email is never sent twice', function () {
    Notification::fake();
    Exceptions::fake();
    $broken = true;
    DeliveryLog::creating(function (DeliveryLog $log) use (&$broken) {
        if ($broken) {
            throw new QueryException('pgsql', 'insert into "alert_notifications" ("target") values (?)', [$log->target], new PDOException('connection lost'));
        }
    });

    dispatch(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com')->onConnection('database'));

    workOneEmail();
    $this->travel(10)->seconds();
    workOneEmail();
    $this->travel(60)->seconds();
    workOneEmail();

    Notification::assertNothingSent();

    $reported = collect(Exceptions::reported())->map(fn (Throwable $exception) => $exception::class.' '.$exception->getMessage().' '.$exception->getTraceAsString().' '.TraceArguments::ofAppFrames($exception))->implode("\n");

    expect(DeliveryLog::query()->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(json_encode(DB::table('failed_jobs')->get()))->not->toContain('ops@example.com')
        ->and($reported)->not->toBe('')
        ->not->toContain('ops@example.com');

    $broken = false;
    $job = emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com');

    dispatch_sync($job);
    dispatch_sync($job);
    $job->failed(new RuntimeException('mail'));

    Notification::assertSentOnDemandTimes(AlertNotification::class, 1);

    expect(DeliveryLog::query()->sole()->delivery_id)->toBe($job->deliveryId)
        ->and(DeliveryLog::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});

test('every email job carries its own delivery id', function () {
    $first = emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com');
    $second = emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com');

    expect($first->deliveryId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($first->deliveryId)->not->toBe($second->deliveryId)
        ->and(unserialize(serialize($first))->deliveryId)->toBe($first->deliveryId);
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

test('an alert email that is no longer due when the worker picks it up is dropped without a log row', function (SentNotificationKind $kind, array $payload, array $attributes, bool $sent) {
    Notification::fake();
    $this->alert->update($attributes);

    dispatch_sync(emailJob($this->team, $this->alert->id, $kind, null, 'ops@example.com', $payload));

    expect(Notification::sentNotifications() !== [])->toBe($sent)
        ->and(DeliveryLog::query()->count())->toBe($sent ? 1 : 0);
})->with([
    'opening of an open alert' => [SentNotificationKind::CriticalAlert, [], [], true],
    'opening muted meanwhile' => [SentNotificationKind::CriticalAlert, [], ['muted_until' => '2026-09-17 13:00:00'], false],
    'opening muted until resolved' => [SentNotificationKind::CriticalAlert, [], ['muted_indefinitely' => true], false],
    'opening after an expired mute' => [SentNotificationKind::CriticalAlert, [], ['muted_until' => '2026-09-17 11:59:00'], true],
    'opening superseded by the resolution' => [SentNotificationKind::CriticalAlert, [], ['resolved_at' => '2026-09-17 11:59:00'], false],
    'opening of a handled alert' => [SentNotificationKind::CriticalAlert, [], ['handled_at' => '2026-09-17 11:59:00'], true],
    'repetition of a handled alert' => [SentNotificationKind::CriticalRepeated, [], ['handled_at' => '2026-09-17 11:59:00'], false],
    'repetition superseded by the resolution' => [SentNotificationKind::CriticalRepeated, [], ['resolved_at' => '2026-09-17 11:59:00'], false],
    'resolution' => [SentNotificationKind::Resolved, [], ['resolved_at' => '2026-09-17 11:59:00'], true],
    'resolution of an alert muted at the time' => [SentNotificationKind::Resolved, [], ['resolved_at' => '2026-09-17 11:59:00', 'muted_until' => '2026-09-17 13:00:00'], false],
    'resolution after the mute expired' => [SentNotificationKind::Resolved, [], ['resolved_at' => '2026-09-17 11:59:00', 'muted_until' => '2026-09-17 11:58:00'], true],
    'resolution muted until resolved' => [SentNotificationKind::Resolved, [], ['resolved_at' => '2026-09-17 11:59:00', 'muted_indefinitely' => true], false],
    'resolution of an alert still open' => [SentNotificationKind::Resolved, [], [], false],
]);

test('an alert email skips a member who no longer sees the environment, silently, while an extra address still gets it', function () {
    Notification::fake();
    $this->team->members()->updateExistingPivot($this->admin->id, ['visibility' => MemberVisibility::NonProduction->value]);

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, $this->admin->id, null));
    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::CriticalAlert, null, 'ops@example.com'));

    Notification::assertSentOnDemandTimes(AlertNotification::class, 1);
    expect(DeliveryLog::query()->pluck('target')->all())->toBe(['ops@example.com']);
});

test('the resolution of an alert whose environment is gone reaches only members who see everything', function (MemberVisibility $visibility, bool $sent) {
    Notification::fake();
    $this->team->members()->updateExistingPivot($this->admin->id, ['visibility' => $visibility->value]);
    $this->alert->forceFill(['environment_id' => null, 'resolved_at' => now()])->save();

    dispatch_sync(emailJob($this->team, $this->alert->id, SentNotificationKind::Resolved, $this->admin->id, null));

    expect(DeliveryLog::query()->count())->toBe($sent ? 1 : 0);
})->with([
    'all' => [MemberVisibility::All, true],
    'non production' => [MemberVisibility::NonProduction, false],
]);

test('a digest email keeps only the warnings its member still sees when it is sent', function () {
    Notification::fake();
    $staging = Environment::factory()->for($this->application)->staging()->create();
    $production = Alert::factory()->for($this->environment)->warning()->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    $visible = Alert::factory()->for($staging)->warning()->create();
    $this->team->members()->updateExistingPivot($this->admin->id, ['visibility' => MemberVisibility::NonProduction->value]);

    dispatch_sync(emailJob($this->team, null, SentNotificationKind::WarningDigest, $this->admin->id, null, ['alertIds' => [$production->id, $visible->id], 'environmentCount' => 2]));

    Notification::assertSentOnDemand(
        WarningDigestNotification::class,
        fn (WarningDigestNotification $notification) => $notification->alerts->pluck('id')->all() === [$visible->id],
    );

    dispatch_sync(emailJob($this->team, null, SentNotificationKind::WarningDigest, $this->admin->id, null, ['alertIds' => [$production->id], 'environmentCount' => 1]));

    Notification::assertSentOnDemandTimes(WarningDigestNotification::class, 1);
    expect(DeliveryLog::query()->count())->toBe(1);
});

test('an alert webhook that is no longer due when the worker picks it up is dropped without a request or a log row', function (string $event, array $attributes, bool $sent) {
    $this->app->instance(Resolver::class, new FakeResolver(['hooks.example.com' => ['203.0.113.10']]));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 204)]);
    $this->alert->update($attributes);

    dispatch_sync(new SendAlertWebhook($this->team->id, $this->alert->id, SentNotificationKind::CriticalAlert, $event, WebhookPayload::forAlert($this->alert, $event)));

    Http::assertSentCount($sent ? 1 : 0);
    expect(DeliveryLog::query()->count())->toBe($sent ? 1 : 0);
})->with([
    'opening' => [WebhookPayload::OPENED, [], true],
    'opening muted meanwhile' => [WebhookPayload::OPENED, ['muted_indefinitely' => true], false],
    'opening superseded by the resolution' => [WebhookPayload::OPENED, ['resolved_at' => '2026-09-17 11:59:00'], false],
    'repetition of a handled alert' => [WebhookPayload::REPEATED, ['handled_at' => '2026-09-17 11:59:00'], false],
    'resolution' => [WebhookPayload::RESOLVED, ['resolved_at' => '2026-09-17 11:59:00'], true],
    'resolution of an alert muted at the time' => [WebhookPayload::RESOLVED, ['resolved_at' => '2026-09-17 11:59:00', 'muted_until' => '2026-09-17 13:00:00'], false],
]);

test('a digest or test webhook does not depend on any alert', function (SentNotificationKind $kind, string $event) {
    $this->app->instance(Resolver::class, new FakeResolver(['hooks.example.com' => ['203.0.113.10']]));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 204)]);

    dispatch_sync(new SendAlertWebhook($this->team->id, null, $kind, $event, ['event' => $event]));

    Http::assertSentCount(1);
})->with([
    'digest' => [SentNotificationKind::WarningDigest, WebhookPayload::DIGEST],
    'test' => [SentNotificationKind::Test, WebhookPayload::TEST],
]);
