<?php

use App\Actions\Alerts\DispatchDueNotifications;
use App\Alerts\EffectiveRules;
use App\Enums\AlertRuleMetric;
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
