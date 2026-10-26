<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Tests\Support\AnotherAppKey;

test('the command turns two-factor authentication off, even when its secret no longer decrypts', function () {
    Event::fake([TwoFactorAuthenticationDisabled::class]);

    $user = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);
    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => AnotherAppKey::encrypt('secret'),
        'two_factor_recovery_codes' => AnotherAppKey::encrypt('["recovery-code-1"]'),
    ]);

    $this->artisan('users:reset-two-factor', ['email' => 'Ada@Example.com'])
        ->expectsOutput('Two-factor authentication is off for ada@example.com.')
        ->assertSuccessful();

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    Event::assertDispatched(TwoFactorAuthenticationDisabled::class);

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertSessionMissing('login.id');

    $this->assertAuthenticatedAs($user);
});

test('an account without two-factor authentication is left as it is', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('users:reset-two-factor', ['email' => 'ada@example.com'])
        ->expectsOutput('ada@example.com does not use two-factor authentication.')
        ->assertSuccessful();
});

test('an unknown address fails', function () {
    $this->artisan('users:reset-two-factor', ['email' => 'nobody@example.com'])
        ->expectsOutput('No account uses nobody@example.com.')
        ->assertFailed();
});
