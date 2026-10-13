<?php

use App\Models\User;
use App\Notifications\Auth\EmailChanged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->missing('mustVerifyEmail')
            ->missing('status'),
        );
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('the name can be changed without the password', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Renamed',
            'email' => 'Owner@Example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->name)->toBe('Renamed')
        ->and($user->email)->toBe('owner@example.com');
});

test('changing the email needs the current password', function (array $password, string $error) {
    Notification::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'attacker@example.com',
            ...$password,
        ])
        ->assertSessionHasErrors(['current_password' => $error])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email)->toBe('owner@example.com');
    Notification::assertNothingSent();
})->with([
    'missing' => [[], 'The current password field is required.'],
    'wrong' => [['current_password' => 'wrong-password'], 'The password is incorrect.'],
]);

test('changing the email tells the previous address', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'owner@example.com', 'locale' => 'it']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(EmailChanged::class, function (EmailChanged $notification, array $channels, AnonymousNotifiable $notifiable) {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes === ['mail' => 'owner@example.com']
            && $notification->locale === 'it'
            && str_contains((string) $mail->introLines[0], 'owner@example.com')
            && str_contains((string) $mail->introLines[0], 'new@example.com');
    });
    Notification::assertCount(1);
});

test('an unchanged email sends no notice', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Another Name',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('an address taken in a different case is refused', function () {
    User::factory()->create(['email' => 'colleague@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'Colleague@Example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

test('profile updates are rate limited', function () {
    config(['horizon-watch.rate_limits.current_password_per_minute' => 2]);

    $user = User::factory()->create();

    $update = fn () => $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ]);

    $update()->assertSessionHasErrors('current_password');
    $update()->assertSessionHasErrors('current_password');
    $update()->assertTooManyRequests();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
