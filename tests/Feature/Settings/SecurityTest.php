<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

test('security page is displayed', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('canManageTwoFactor', true)
            ->where('twoFactorEnabled', false),
        );
});

test('security page requires password confirmation when enabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('security page renders without two factor when feature is disabled', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('canManageTwoFactor', false)
            ->missing('twoFactorEnabled')
            ->missing('requiresConfirmation'),
        );
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect(Hash::check('new-password-1', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ]);

    $response
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('security.edit'));
});

test('changing the password logs out the other sessions and keeps this one', function () {
    $user = User::factory()->create();
    $previousHash = $user->password;

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('profile.edit'))->assertOk();

    $this->withSession(['password_hash_web' => $previousHash])
        ->get(route('profile.edit'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('changing the password deletes the other stored sessions of the account', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        ['id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'stolen-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'someone-else')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

test('other sessions can be logged out with the password', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $previousHash = $user->password;

    DB::table('sessions')->insert([
        'id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('other-sessions.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $user->refresh();

    expect(DB::table('sessions')->where('id', 'stolen-session')->exists())->toBeFalse()
        ->and($user->password)->not->toBe($previousHash)
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('logging out other sessions needs the right password', function () {
    $user = User::factory()->create();
    $previousHash = $user->password;

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('other-sessions.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('security.edit'));

    expect($user->refresh()->password)->toBe($previousHash);
});

test('logging out other sessions is rate limited', function () {
    config(['horizon-watch.rate_limits.current_password_per_minute' => 1]);

    $user = User::factory()->create();

    $logout = fn () => $this->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('other-sessions.destroy'), ['password' => 'wrong-password']);

    $logout()->assertSessionHasErrors('password');
    $logout()->assertTooManyRequests();
});

test('a remember-me cookie stops working once the password changes elsewhere', function () {
    $user = User::factory()->create();
    $recaller = Auth::guard()->getRecallerName();

    $cookie = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
        ->getCookie($recaller)
        ?->getValue();

    expect($cookie)->toBeString();

    $remembered = function () use ($recaller, $cookie) {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->withCookie($recaller, (string) $cookie)->get(route('profile.edit'));
    };

    $remembered()->assertOk();
    $this->assertAuthenticatedAs($user);

    $user->update(['password' => 'new-password-1']);

    $remembered()->assertRedirect(route('login'));
    $this->assertGuest();
});

test('changing the password keeps remember-me working on this device', function () {
    $user = User::factory()->create();
    $recaller = Auth::guard()->getRecallerName();

    $cookie = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
        ->getCookie($recaller)
        ?->getValue();

    $renewed = $this->withCookie($recaller, (string) $cookie)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])
        ->assertSessionHasNoErrors()
        ->getCookie($recaller)
        ?->getValue();

    expect($renewed)->toBeString()->not->toBe($cookie);

    Auth::forgetGuards();
    $this->flushSession();

    $this->withCookie($recaller, (string) $renewed)->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('two-factor authentication is set up, confirmed, given new recovery codes and turned off', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'))->assertRedirect();

    $user->refresh();
    $secret = decrypt($user->two_factor_secret);

    expect($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($user->recoveryCodes())->toHaveCount(8);

    $this->getJson(route('two-factor.secret-key'))->assertOk()->assertJson(['secretKey' => $secret]);
    $this->getJson(route('two-factor.qr-code'))->assertOk()->assertJsonStructure(['svg', 'url']);

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->from(route('security.edit'))
        ->post(route('two-factor.confirm'), ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors('code', errorBag: 'confirmTwoFactorAuthentication');

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull();

    $this->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasNoErrors();

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', true));

    $codes = $this->getJson(route('two-factor.recovery-codes'))->assertOk()->json();

    $this->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect();

    expect($user->refresh()->recoveryCodes())->toHaveCount(8)
        ->and(array_intersect($codes, $user->recoveryCodes()))->toBe([]);

    $this->delete(route('two-factor.disable'))->assertRedirect();

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('managing two-factor authentication needs a recently confirmed password', function (string $method, string $route) {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create();
    $secret = $user->two_factor_secret;

    $this->actingAs($user)
        ->{$method}(route($route))
        ->assertRedirect(route('password.confirm'));

    expect($user->refresh()->two_factor_secret)->toBe($secret);
})->with([
    'enable' => ['post', 'two-factor.enable'],
    'confirm' => ['post', 'two-factor.confirm'],
    'show the QR code' => ['get', 'two-factor.qr-code'],
    'disable' => ['delete', 'two-factor.disable'],
    'show the secret' => ['get', 'two-factor.secret-key'],
    'show the recovery codes' => ['get', 'two-factor.recovery-codes'],
    'new recovery codes' => ['post', 'two-factor.regenerate-recovery-codes'],
]);
