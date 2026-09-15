<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the sign-in pages render once the panel is set up', function (string $uri, string $component) {
    User::factory()->create();

    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['/login', 'auth/Login'],
    ['/forgot-password', 'auth/ForgotPassword'],
]);

test('the setup page renders on a fresh panel', function () {
    $this->get('/setup')->assertInertia(fn (Assert $page) => $page->component('auth/Setup'));
});
