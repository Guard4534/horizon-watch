<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('auth/ConfirmPassword'),
    );
});

test('password confirmation requires authentication', function () {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(route('login'));
});

test('password confirmation attempts are rate limited', function () {
    config(['horizon-watch.rate_limits.current_password_per_minute' => 2]);

    $user = User::factory()->create();

    $confirm = fn () => $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password']);

    $confirm()->assertSessionHasErrors('password');
    $confirm()->assertSessionHasErrors('password');
    $confirm()->assertTooManyRequests();
});
