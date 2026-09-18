<?php

use App\Alerts\AlertDelivery;
use App\Enums\MemberVisibility;
use App\Enums\NotificationChannel;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->memberOf = function (TeamRole $role): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->admin = ($this->memberOf)(TeamRole::Admin);

    $this->delivery = new class implements AlertDelivery
    {
        public int $targets = 2;

        /** @var list<array{int, NotificationChannel, int}> */
        public array $calls = [];

        public function sendTest(Team $team, NotificationChannel $channel, User $requestedBy): int
        {
            $this->calls[] = [$team->id, $channel, $requestedBy->id];

            return $this->targets;
        }
    };

    $this->app->instance(AlertDelivery::class, $this->delivery);

    $this->send = fn (mixed $channel) => $this->post(
        route('alert-settings.test', ['current_team' => $this->team->slug]),
        ['channel' => $channel],
    );
});

test('a manager sends a test on a channel and learns how many destinations it reached', function (string $channel, int $targets, string $message) {
    $this->delivery->targets = $targets;

    $this->actingAs($this->admin);

    ($this->send)($channel)
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $message]);

    expect($this->delivery->calls)->toBe([[$this->team->id, NotificationChannel::from($channel), $this->admin->id]]);
})->with([
    'mail' => ['mail', 3, 'Test sent to 3 destinations.'],
    'webhook' => ['webhook', 1, 'Test sent to one destination.'],
]);

test('nothing to send to is refused with a message', function () {
    $this->delivery->targets = 0;

    $this->actingAs($this->admin);

    ($this->send)('webhook')
        ->assertSessionHasErrors(['channel' => 'There is nowhere to send a test yet: save the notification settings first.'])
        ->assertInertiaFlashMissing('toast');

    expect($this->delivery->calls)->toHaveCount(1);
});

test('an unknown channel is refused before anything is sent', function (mixed $channel) {
    $this->actingAs($this->admin);

    ($this->send)($channel)->assertSessionHasErrors('channel');

    expect($this->delivery->calls)->toBe([]);
})->with(['push', null, '']);

test('only a role that manages alert rules may send a test', function (TeamRole $role, bool $allowed) {
    $this->actingAs(($this->memberOf)($role));

    $response = ($this->send)('mail');

    if ($allowed) {
        $response->assertRedirect();
        expect($this->delivery->calls)->toHaveCount(1);
    } else {
        $response->assertForbidden();
        expect($this->delivery->calls)->toBe([]);
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('five tests a minute per person, then the sixth is throttled', function () {
    config(['horizon-watch.test_notification_per_minute' => 5]);
    $other = ($this->memberOf)(TeamRole::Admin);

    $this->actingAs($this->admin);

    foreach (range(1, 5) as $attempt) {
        ($this->send)('mail')->assertRedirect();
    }

    ($this->send)('mail')->assertStatus(429);
    expect($this->delivery->calls)->toHaveCount(5);

    $this->actingAs($other);
    ($this->send)('mail')->assertRedirect();
    expect($this->delivery->calls)->toHaveCount(6);

    $this->travel(61)->seconds();
    $this->actingAs($this->admin);
    ($this->send)('webhook')->assertRedirect();
    expect($this->delivery->calls)->toHaveCount(7);
});

test('the throttle follows the configured limit and is separate from the connection test', function () {
    config(['horizon-watch.test_notification_per_minute' => 2, 'horizon-watch.test_connection_per_minute' => 1]);

    $this->actingAs($this->admin);

    ($this->send)('mail')->assertRedirect();
    ($this->send)('mail')->assertRedirect();
    ($this->send)('mail')->assertStatus(429);

    expect(RateLimiter::tooManyAttempts(md5('test-connection'.$this->admin->id), 1))->toBeFalse();
});

test('an Inertia visit that hits the throttle goes back with a toast', function () {
    config(['horizon-watch.test_notification_per_minute' => 1]);

    $this->actingAs($this->admin);
    ($this->send)('mail');

    $this->from(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->withHeader('X-Inertia', 'true')
        ->post(route('alert-settings.test', ['current_team' => $this->team->slug]), ['channel' => 'mail'])
        ->assertRedirect(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast.type', 'error');
});
