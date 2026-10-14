<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AnotherAppKey;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

test('two factor challenge redirects to login when not authenticated', function () {
    User::factory()->create();

    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/TwoFactorChallenge'),
        );
});

test('completing the challenge without an organization lands on the organizations page', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->withoutOrganization()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-1',
    ])->assertRedirect(route('teams.index'));

    $this->assertAuthenticatedAs($user);
});

test('a two-factor secret that no longer decrypts answers with an error instead of a 500', function (string $field) {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();
    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => AnotherAppKey::encrypt('secret'),
        'two_factor_recovery_codes' => AnotherAppKey::encrypt('["recovery-code-1"]'),
    ]);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), [$field => '123456'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors([$field => 'The two-factor secret of this account can no longer be read: ask an administrator to turn two-factor authentication off.'])
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
})->with(['code', 'recovery_code']);

function currentCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
}

test('a valid code completes the sign-in and lands on the wall of the organization', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['code' => currentCode($user)])
        ->assertRedirect(route('wall', ['current_team' => $user->currentTeam->slug]));

    $this->assertAuthenticatedAs($user);
});

test('a wrong code keeps the challenge open', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $wrong = currentCode($user) === '000000' ? '111111' : '000000';

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), ['code' => $wrong])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors('code')
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('a code cannot be used twice', function () {
    $user = User::factory()->withTwoFactor()->create();
    $code = currentCode($user);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => $code]);
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('a recovery code works once and is replaced', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect(route('wall', ['current_team' => $user->currentTeam->slug]));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->recoveryCodes())->toHaveCount(1)->not->toContain('recovery-code-1');

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
});

test('the challenge is rate limited per pending sign-in', function () {
    config(['horizon-watch.rate_limits.two_factor_per_minute' => 2]);

    $user = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $attempt = fn () => $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-code']);

    $attempt()->assertSessionHasErrors('recovery_code');
    $attempt()->assertSessionHasErrors('recovery_code');
    $attempt()->assertTooManyRequests();

    $this->post(route('two-factor.login.store'), ['code' => currentCode($user)])->assertTooManyRequests();
    $this->assertGuest();

    $this->flushSession();
    $this->post(route('login'), ['email' => $other->email, 'password' => 'password']);

    $this->post(route('two-factor.login.store'), ['code' => currentCode($other)])
        ->assertRedirect(route('wall', ['current_team' => $other->currentTeam->slug]));
});
