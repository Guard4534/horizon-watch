<?php

use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnotherAppKey;

function encrypterWithPreviousKey(): Encrypter
{
    $cipher = config()->string('app.cipher');
    $previous = Encrypter::generateKey($cipher);

    app('encrypter')->previousKeys([$previous]);

    return new Encrypter($previous, $cipher);
}

test('every stored secret is encrypted again with the current key, so the previous key can go', function () {
    $previous = encrypterWithPreviousKey();

    $environment = Environment::factory()->create(['basic_auth_user' => 'monitor', 'basic_auth_password' => 'old']);
    $setting = NotificationSetting::factory()->withWebhook()->create();
    $user = User::factory()->withTwoFactor()->create();
    $updatedAt = $environment->updated_at;

    DB::table('environments')->where('id', $environment->id)->update(['basic_auth_password' => $previous->encryptString('horizon-password')]);
    DB::table('notification_settings')->where('team_id', $setting->team_id)->update(['webhook_secret' => $previous->encryptString('signing-secret')]);
    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => $previous->encrypt('totp-secret'),
        'two_factor_recovery_codes' => $previous->encrypt('["recovery-code-1"]'),
    ]);

    $this->artisan('secrets:reencrypt')
        ->expectsOutput('Encrypted 4 secrets again with the current key.')
        ->assertSuccessful();

    app('encrypter')->previousKeys([]);

    $environment->refresh();
    $user->refresh();

    expect($environment->basic_auth_password)->toBe('horizon-password')
        ->and($environment->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($setting->refresh()->webhook_secret)->toBe('signing-secret')
        ->and(decrypt($user->two_factor_secret))->toBe('totp-secret')
        ->and($user->recoveryCodes())->toBe(['recovery-code-1']);
});

test('a secret no key can decrypt is left as it is and fails the command', function () {
    $environment = Environment::factory()->create(['basic_auth_user' => 'monitor', 'basic_auth_password' => 'readable']);
    $unreadable = Environment::factory()->create(['basic_auth_user' => 'monitor', 'basic_auth_password' => 'old']);
    $stored = AnotherAppKey::encrypt('lost');
    DB::table('environments')->where('id', $unreadable->id)->update(['basic_auth_password' => $stored]);

    $this->artisan('secrets:reencrypt')
        ->expectsOutput("environments.basic_auth_password of {$unreadable->id} cannot be decrypted with any configured key.")
        ->expectsOutput('Encrypted 1 secret again with the current key.')
        ->expectsOutput('1 secret could not be decrypted: set it again before removing a previous key.')
        ->assertFailed();

    expect(DB::table('environments')->where('id', $unreadable->id)->value('basic_auth_password'))->toBe($stored)
        ->and($environment->refresh()->basic_auth_password)->toBe('readable');
});

test('a replaced webhook secret still in use is encrypted again, an expired one is dropped first', function () {
    $previous = encrypterWithPreviousKey();
    $current = NotificationSetting::factory()->withWebhook()->create();
    $expired = NotificationSetting::factory()->withWebhook()->create();

    DB::table('notification_settings')->where('team_id', $current->team_id)->update([
        'previous_webhook_secret' => $previous->encryptString('replaced-secret'),
        'previous_webhook_secret_expires_at' => now()->addHour(),
    ]);
    DB::table('notification_settings')->where('team_id', $expired->team_id)->update([
        'previous_webhook_secret' => AnotherAppKey::encrypt('long-gone'),
        'previous_webhook_secret_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('secrets:reencrypt')
        ->expectsOutput('Encrypted 3 secrets again with the current key.')
        ->assertSuccessful();

    app('encrypter')->previousKeys([]);

    expect($current->refresh()->previous_webhook_secret)->toBe('replaced-secret')
        ->and($expired->refresh()->getRawOriginal('previous_webhook_secret'))->toBeNull()
        ->and($expired->previous_webhook_secret_expires_at)->toBeNull();
});
