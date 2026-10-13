<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
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
