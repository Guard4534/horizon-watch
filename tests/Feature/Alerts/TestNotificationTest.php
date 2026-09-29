<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Jobs\SendAlertEmail;
use App\Jobs\SendAlertWebhook;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Queue::fake();

    $this->team = Team::factory()->create();

    $this->memberOf = function (TeamRole $role): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->admin = ($this->memberOf)(TeamRole::Admin);

    $this->settings = fn (array $attributes) => NotificationSetting::factory()->for($this->team)->create($attributes);

    $this->send = fn (mixed $channel) => $this->post(
        route('alert-settings.test', ['current_team' => $this->team->slug]),
        ['channel' => $channel],
    );

    $this->queued = fn (string $job) => Queue::pushed($job)->count();
});

test('a manager sends a test on a channel and learns how many destinations it reached', function (string $channel, string $job, int $targets, string $message) {
    ($this->settings)([
        'recipients' => ['ops@example.com', 'oncall@example.com'],
        'webhook_url' => 'https://hooks.example.com/horizon',
        'webhook_secret' => str_repeat('s', 40),
    ]);

    $this->actingAs($this->admin);

    ($this->send)($channel)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);

    expect(($this->queued)($job))->toBe($targets);
})->with([
    'mail' => ['mail', SendAlertEmail::class, 3, 'Test sent to 3 destinations.'],
    'webhook' => ['webhook', SendAlertWebhook::class, 1, 'Test sent to one destination.'],
]);

test('nothing to send to is refused with a message', function () {
    $this->actingAs($this->admin);

    ($this->send)('webhook')
        ->assertSessionHasErrors(['channel' => 'There is nowhere to send a test yet: save the notification settings first.'])
        ->assertInertiaFlashMissing('toast');

    Queue::assertNothingPushed();
});

test('an unknown channel is refused before anything is sent', function (mixed $channel) {
    $this->actingAs($this->admin);

    ($this->send)($channel)->assertSessionHasErrors('channel');

    Queue::assertNothingPushed();
})->with(['push', null, '']);

test('only a role that manages alert rules may send a test', function (TeamRole $role, bool $allowed) {
    $this->actingAs(($this->memberOf)($role));

    $response = ($this->send)('mail');

    if ($allowed) {
        $response->assertRedirect();
        expect(($this->queued)(SendAlertEmail::class))->toBe(1);
    } else {
        $response->assertForbidden();
        Queue::assertNothingPushed();
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('five tests a minute per person, then the sixth is throttled', function () {
    config(['horizon-watch.rate_limits.test_notification_per_minute' => 5]);
    ($this->settings)(['webhook_url' => 'https://hooks.example.com/horizon', 'webhook_secret' => str_repeat('s', 40)]);
    $other = ($this->memberOf)(TeamRole::Admin);

    $this->actingAs($this->admin);

    foreach (range(1, 5) as $attempt) {
        ($this->send)('mail')->assertRedirect();
    }

    ($this->send)('mail')->assertStatus(429);
    expect(($this->queued)(SendAlertEmail::class))->toBe(5);

    $this->actingAs($other);
    ($this->send)('mail')->assertRedirect();
    expect(($this->queued)(SendAlertEmail::class))->toBe(6);

    $this->travel(61)->seconds();
    $this->actingAs($this->admin);
    ($this->send)('webhook')->assertRedirect();
    expect(($this->queued)(SendAlertWebhook::class))->toBe(1);
});

test('the throttle follows the configured limit and is separate from the connection test', function () {
    config([
        'horizon-watch.rate_limits.test_notification_per_minute' => 2,
        'horizon-watch.rate_limits.test_connection_per_minute' => 1,
    ]);

    $this->actingAs($this->admin);

    ($this->send)('mail')->assertRedirect();
    ($this->send)('mail')->assertRedirect();
    ($this->send)('mail')->assertStatus(429);

    expect(RateLimiter::tooManyAttempts(md5('test-connection'.$this->admin->id), 1))->toBeFalse();
});

test('an Inertia visit that hits the throttle goes back with a toast', function () {
    config(['horizon-watch.rate_limits.test_notification_per_minute' => 1]);

    $this->actingAs($this->admin);
    ($this->send)('mail');

    $this->from(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->withHeader('X-Inertia', 'true')
        ->post(route('alert-settings.test', ['current_team' => $this->team->slug]), ['channel' => 'mail'])
        ->assertRedirect(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast.type', 'error');
});
