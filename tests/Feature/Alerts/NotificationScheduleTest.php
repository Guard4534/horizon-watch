<?php

use App\Actions\Alerts\DispatchDueNotifications;
use App\Alerts\EffectiveRules;
use App\Enums\AlertRuleMetric;
use App\Enums\SentNotificationKind;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AlertTeam;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    $this->environment = Environment::factory()->for(Application::factory()->for($this->team))->create();
    AlertTeam::member($this->team, 'admin@example.com');
    NotificationSetting::factory()->for($this->team)->withWebhook()->create();
    Queue::fake();
});

function scheduledAlertEvent(string $name): ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => $event->description === $name)
        ?? throw new RuntimeException("No scheduled event {$name}.");
}

test('repetitions are checked every minute and digests every quarter of an hour, once', function (string $name, string $expression) {
    $event = scheduledAlertEvent($name);

    expect($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->onOneServer)->toBeTrue();
})->with([
    ['alert-repeats', '* * * * *'],
    ['alert-digests', '*/15 * * * *'],
]);

test('digests are due on the clock quarters only', function () {
    $event = scheduledAlertEvent('alert-digests');

    foreach (['12:00' => true, '12:15' => true, '12:30' => true, '12:45' => true, '12:01' => false, '12:14' => false, '12:59' => false] as $time => $due) {
        $this->travelTo(CarbonImmutable::parse("2026-09-17 {$time}:00", 'UTC'));

        expect($event->isDue(app()))->toBe($due, $time);
    }
});

test('the scheduled repetition queues the overdue critical alerts', function () {
    Alert::factory()->for($this->environment)->critical()->create([
        'notified' => true,
        'last_notified_at' => now()->subMinutes(30),
    ]);

    $this->artisan('schedule:test', ['--name' => 'alert-repeats'])->assertSuccessful();

    Queue::assertPushed(SendAlertEmail::class, 1);
    Queue::assertPushed(SendAlertWebhook::class, fn (SendAlertWebhook $job) => $job->event === 'alert.repeated');
});

test('the scheduled digest queues the pending warnings', function () {
    Alert::factory()->for($this->environment)->warning()->create();

    $this->artisan('schedule:test', ['--name' => 'alert-digests'])->assertSuccessful();

    Queue::assertPushed(SendAlertEmail::class, 1);
    Queue::assertPushed(SendAlertWebhook::class, fn (SendAlertWebhook $job) => $job->event === 'alert.digest');
});

test('each scheduled run reads the rules afresh in a long-lived process', function () {
    app(EffectiveRules::class)->forEnvironment($this->environment);

    Alert::factory()->for($this->environment)->warning()->create(['metric' => AlertRuleMetric::QueuePending]);
    app(DispatchDueNotifications::class)->digests();

    Queue::assertPushed(SendAlertEmail::class, 1);

    AlertRule::factory()->for($this->team)->inheriting()->create(['metric' => AlertRuleMetric::QueueMaxWait, 'notify_email' => false]);
    Alert::factory()->for($this->environment)->warning()->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    Queue::fake();
    app(DispatchDueNotifications::class)->digests();

    Queue::assertNotPushed(SendAlertEmail::class);
    Queue::assertPushed(SendAlertWebhook::class, 1);
});

test('each scheduled repetition reads the rules afresh too', function () {
    app(EffectiveRules::class)->forEnvironment($this->environment);
    AlertRule::factory()->for($this->team)->inheriting()->create(['metric' => AlertRuleMetric::QueuePending, 'notify_email' => false]);
    Alert::factory()->for($this->environment)->critical()->create(['metric' => AlertRuleMetric::QueuePending]);

    app(DispatchDueNotifications::class)->repeats();

    Queue::assertNotPushed(SendAlertEmail::class);
    Queue::assertPushed(SendAlertWebhook::class, 1);
});

test('the scheduled repetition also catches up a resolution whose notice was lost', function () {
    $alert = Alert::factory()->for($this->environment)->critical()->create([
        'notified' => true,
        'last_notified_at' => now()->subMinutes(20),
        'resolved_at' => now()->subMinutes(2),
    ]);

    $this->artisan('schedule:test', ['--name' => 'alert-repeats'])->assertSuccessful();

    Queue::assertPushed(SendAlertEmail::class, fn (SendAlertEmail $job) => $job->kind === SentNotificationKind::Resolved && $job->alertId === $alert->id);
    Queue::assertPushed(SendAlertWebhook::class, fn (SendAlertWebhook $job) => $job->event === 'alert.resolved');

    expect($alert->refresh()->resolution_notified_at)->not->toBeNull();
});

test('a failing step of the per-minute run does not stop the other, and is reported by class only', function (string $query, string $reported, string $event) {
    Exceptions::fake();
    Alert::factory()->for($this->environment)->critical()->create([
        'metric' => AlertRuleMetric::HorizonMasterInactive,
        'notified' => true,
        'last_notified_at' => now()->subHour(),
    ]);
    Alert::factory()->for($this->environment)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'notified' => true,
        'last_notified_at' => now()->subMinutes(20),
        'resolved_at' => now()->subMinutes(2),
    ]);

    DB::listen(function (QueryExecuted $executed) use ($query) {
        if (str_starts_with($executed->sql, 'select') && str_contains($executed->sql, $query)) {
            throw new RuntimeException('secret-detail');
        }
    });

    app(DispatchDueNotifications::class)->repeats();

    Queue::assertPushed(SendAlertWebhook::class, 1);
    Queue::assertPushed(SendAlertWebhook::class, fn (SendAlertWebhook $job) => $job->event === $event);

    $messages = collect(Exceptions::reported())->map(fn (Throwable $exception) => $exception->getMessage());

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toStartWith("Alert {$reported} threw RuntimeException at ")
        ->and($messages->first())->not->toContain('secret-detail');
})->with([
    'repetitions' => ['make_interval(mins', 'repetitions', 'alert.resolved'],
    'resolutions' => ['"alerts"."resolution_notified_at" is null', 'resolutions', 'alert.repeated'],
]);
