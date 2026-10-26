<?php

use App\Models\User;
use App\Notifications\Auth\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    User::factory()->create();

    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('the reset link points to the configured url whatever host the request claims', function (string $uri, array $headers) {
    Notification::fake();
    config(['app.url' => 'https://watch.example.com']);

    $user = User::factory()->create();

    $this->post($uri, ['email' => $user->email], $headers);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        expect($notification->toMail($user)->actionUrl)
            ->toStartWith('https://watch.example.com/reset-password/'.$notification->token);

        return true;
    });
})->with([
    'forged host' => ['http://evil.example/forgot-password', []],
    'forged forwarded host' => ['/forgot-password', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https']],
]);

test('the reset email is queued and encrypted on the queue', function () {
    expect(new ResetPassword('token'))
        ->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('the reset email follows the language of the account', function () {
    Notification::fake();

    $user = User::factory()->create(['locale' => 'it']);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, fn (ResetPassword $notification) => $notification->locale === 'it');
});

test('an unknown address gets the same answer as a known one', function () {
    Notification::fake();

    $user = User::factory()->create();

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);
    $throttled = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);

    foreach ([$known, $unknown, $throttled] as $response) {
        $response->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('passwords.sent'));
    }

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('a reset for an unknown address fails like an invalid token', function (string $email) {
    User::factory()->create(['email' => 'known@example.com']);

    $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasErrors(['email' => __('passwords.token')]);
})->with(['known@example.com', 'nobody@example.com']);

test('reset link requests are rate limited per address', function () {
    Notification::fake();
    config(['horizon-watch.rate_limits.password_reset_per_minute' => 2]);

    $this->post(route('password.email'), ['email' => 'one@example.com'])->assertRedirect();
    $this->post(route('password.email'), ['email' => 'two@example.com'])->assertRedirect();
    $this->post(route('password.email'), ['email' => 'three@example.com'])->assertTooManyRequests();
});

test('reset link requests are rate limited per account whatever address they come from', function () {
    Notification::fake();
    config(['horizon-watch.rate_limits.password_reset_per_account_per_hour' => 2]);

    $request = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('password.email'), ['email' => 'Someone@Example.com']);

    $request('203.0.113.1')->assertRedirect();
    $request('203.0.113.2')->assertRedirect();
    $request('203.0.113.3')->assertTooManyRequests();
});

test('resetting the password ends every session of the account', function () {
    Notification::fake();
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        ['id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();

        return true;
    });

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue();
});
