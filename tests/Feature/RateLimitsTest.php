<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

test('a throttled route allows its configured limit and refuses the next request', function (string $route, string $limit) {
    $user = $route === 'setup.store' ? null : User::factory()->withTwoFactor()->create();

    $request = fn (array $headers = []): TestResponse => match ($route) {
        'setup.store' => $this->withHeaders($headers)->post(route('setup.store'), ['name' => 'Ada']),
        'user-password.update' => $this->actingAs($user)->withHeaders($headers)->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ]),
        'locale.update' => $this->withHeaders($headers)->patch(route('locale.update'), ['locale' => 'en']),
        'two-factor.login.store' => $this->withSession(['login.id' => $user?->id])
            ->withHeaders($headers)
            ->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-code']),
    };

    for ($attempt = 0; $attempt < config()->integer("horizon-watch.rate_limits.{$limit}"); $attempt++) {
        expect($request()->status())->not->toBe(429);
    }

    $request()->assertTooManyRequests();

    $request(['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Too many attempts. Wait a minute and try again.',
        ]);
})->with([
    'setup' => ['setup.store', 'setup_per_minute'],
    'password update' => ['user-password.update', 'password_update_per_minute'],
    'locale' => ['locale.update', 'locale_per_minute'],
    'two-factor challenge' => ['two-factor.login.store', 'two_factor_per_minute'],
]);
