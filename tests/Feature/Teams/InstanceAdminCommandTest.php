<?php

use App\Models\User;

test('the command makes an account an instance admin, whatever the case of the address', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->artisan('users:instance-admin', ['email' => 'Ada@Example.com'])
        ->expectsOutput('ada@example.com is now an instance admin.')
        ->assertSuccessful();

    expect($user->fresh()->is_instance_admin)->toBeTrue();
});

test('granting the role twice changes nothing', function () {
    User::factory()->instanceAdmin()->create(['email' => 'ada@example.com']);

    $this->artisan('users:instance-admin', ['email' => 'ada@example.com'])
        ->expectsOutput('ada@example.com is already an instance admin.')
        ->assertSuccessful();
});

test('the command revokes the role while another instance admin remains', function () {
    User::factory()->instanceAdmin()->create(['email' => 'ada@example.com']);
    $grace = User::factory()->instanceAdmin()->create(['email' => 'grace@example.com']);

    $this->artisan('users:instance-admin', ['email' => 'grace@example.com', '--revoke' => true])
        ->expectsOutput('grace@example.com is no longer an instance admin.')
        ->assertSuccessful();

    expect($grace->fresh()->is_instance_admin)->toBeFalse();
});

test('the last instance admin cannot be revoked', function () {
    $ada = User::factory()->instanceAdmin()->create(['email' => 'ada@example.com']);
    User::factory()->create(['email' => 'grace@example.com']);

    $this->artisan('users:instance-admin', ['email' => 'ada@example.com', '--revoke' => true])
        ->expectsOutput('ada@example.com is the last instance admin: grant the role to someone else first.')
        ->assertFailed();

    expect($ada->fresh()->is_instance_admin)->toBeTrue();
});

test('revoking someone who is not an instance admin changes nothing', function () {
    User::factory()->instanceAdmin()->create(['email' => 'ada@example.com']);
    $grace = User::factory()->create(['email' => 'grace@example.com']);

    $this->artisan('users:instance-admin', ['email' => 'grace@example.com', '--revoke' => true])
        ->expectsOutput('grace@example.com is not an instance admin.')
        ->assertSuccessful();

    expect($grace->fresh()->is_instance_admin)->toBeFalse();
});

test('an unknown address fails', function () {
    $this->artisan('users:instance-admin', ['email' => 'nobody@example.com'])
        ->expectsOutput('No account uses nobody@example.com.')
        ->assertFailed();
});
