<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    User::factory()->create();

    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('wall'));
});

test('people without an organization are signed in and sent to the organizations page', function () {
    $user = User::factory()->withoutOrganization()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('teams.index'));

    $this->assertAuthenticatedAs($user);

    $this->get(route('home'))->assertRedirect(route('teams.index'));
});

test('signing in without a current organization picks one the user still belongs to', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $user->update(['current_team_id' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('wall', ['current_team' => $team->slug]));

    expect($user->fresh()->current_team_id)->toBe($team->id);
});

test('the login page sends a signed-in user home instead of looping', function () {
    $user = User::factory()->withoutOrganization()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('home'));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('failed attempts are limited per account whatever address they come from', function () {
    config(['horizon-watch.rate_limits.login_per_account_per_hour' => 2]);

    $user = User::factory()->create();

    $attempt = fn (string $ip, string $password = 'wrong-password') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $user->email, 'password' => $password]);

    expect($attempt('203.0.113.1')->status())->not->toBe(429)
        ->and($attempt('203.0.113.2')->status())->not->toBe(429);

    $attempt('203.0.113.3')->assertTooManyRequests();
    $attempt('203.0.113.4', 'password')->assertTooManyRequests();

    $this->assertGuest();
});

test('the correct password still logs in after failed attempts from other addresses', function () {
    config(['horizon-watch.rate_limits.login_per_account_per_hour' => 2]);

    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('wall'));

    $this->assertAuthenticatedAs($user);
});

test('successful logins do not count towards the per-account limit', function () {
    config(['horizon-watch.rate_limits.login_per_account_per_hour' => 1]);

    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('wall'));

        $this->post(route('logout'));
    }

    $this->assertGuest();
});
