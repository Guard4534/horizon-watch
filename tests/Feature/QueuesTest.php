<?php

use App\Actions\Monitoring\DispatchDuePolls;
use App\Alerts\Events\AlertOpened;
use App\Alerts\Events\AlertResolved;
use App\Enums\SentNotificationKind;
use App\Jobs\PollEnvironmentJob;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Listeners\Alerts\SendOnOpen;
use App\Listeners\Alerts\SendOnResolve;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation as TeamInvitationModel;
use App\Notifications\Auth\EmailChanged;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Teams\TeamInvitation;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\DevCommands;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
});

test('readings go on the polls queue', function () {
    Environment::factory()->create();

    app(DispatchDuePolls::class)->handle();

    Queue::assertPushedOn('polls', PollEnvironmentJob::class);
});

test('alert deliveries go on the notifications queue', function () {
    $team = Team::factory()->create();

    SendAlertEmail::dispatch($team->id, null, SentNotificationKind::Test, null, 'extra', 'en');
    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, []);

    Queue::assertPushedOn('notifications', SendAlertEmail::class);
    Queue::assertPushedOn('notifications', SendAlertWebhook::class);
});

test('the listeners that send an alert go on the notifications queue', function (string $event, string $listener) {
    event(new $event('01992f3c-5a4e-7b1d-9c2a-6d3e4f5a6b7c'));

    Queue::assertPushedOn('notifications', CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === $listener);
})->with([
    'opened' => [AlertOpened::class, SendOnOpen::class],
    'resolved' => [AlertResolved::class, SendOnResolve::class],
]);

test('invitations and account emails go on the notifications queue', function (Closure $notification) {
    Notification::route('mail', 'ada@example.com')->notify($notification());

    Queue::assertPushedOn('notifications', SendQueuedNotifications::class);
})->with([
    'invitation' => [fn () => new TeamInvitation(TeamInvitationModel::factory()->create(), 'code')],
    'password reset' => [fn () => new ResetPassword('token')],
    'email changed' => [fn () => new EmailChanged('ada@example.com', 'ada@example.org')],
]);

test('failed jobs are pruned daily after a week, on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'queue:prune-failed'))
        ?? throw new RuntimeException('No scheduled queue:prune-failed.');

    expect($event->command)->toContain('--hours=168')
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue();
});

test('pruning failed jobs keeps the ones of the last week', function () {
    $insert = fn (string $failedAt) => DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'notifications',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => $failedAt,
    ]);

    $this->travelTo('2026-09-17 10:00:00');
    $insert('2026-09-10 09:00:00');
    $insert('2026-09-10 11:00:00');

    $this->artisan('queue:prune-failed', ['--hours' => config()->integer('horizon-watch.failed_job_retention_hours')])->assertSuccessful();

    expect(DB::table('failed_jobs')->pluck('failed_at')->all())->toBe(['2026-09-10 11:00:00']);
});

test('the dev queue listener serves every queue', function () {
    DevCommands::registerDefaults();

    $queue = collect(DevCommands::commands())->firstWhere('name', 'queue');

    expect($queue['command'] ?? null)->toBe('php artisan queue:listen --queue=notifications,polls,default --tries=1 --timeout=0');
});
