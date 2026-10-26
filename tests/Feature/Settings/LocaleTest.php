<?php

use App\Enums\Locale;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a member keeps the language they chose', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/profile')
        ->patch(route('locale.update'), ['locale' => 'it'])
        ->assertRedirect('/settings/profile');

    expect($user->fresh()->locale)->toBe(Locale::It);

    $this->actingAs($user->fresh())
        ->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
});

beforeEach(fn () => User::factory()->create());

test('a guest keeps the language for the session', function () {
    $this->patch(route('locale.update'), ['locale' => 'it'])->assertRedirect();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
});

test('without a choice the browser language is used when supported', function (string $default, string $header, string $expected) {
    config(['app.locale' => $default]);

    $this->get(route('login'), ['Accept-Language' => $header])
        ->assertInertia(fn (Assert $page) => $page->where('locale', $expected));
})->with([
    'italian browser' => ['en', 'it-IT,it;q=0.9', 'it'],
    'english browser on an italian install' => ['it', 'en-GB,en;q=0.9', 'en'],
    'second choice supported' => ['en', 'de-DE,it;q=0.8', 'it'],
]);

test('APP_LOCALE is used when the browser asks for no supported language', function (string $header) {
    config(['app.locale' => 'it']);

    $this->get(route('login'), ['Accept-Language' => $header])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
})->with([
    'unsupported language' => ['de-DE'],
    'any language' => ['*'],
    'empty header' => [''],
]);

test('english is used when the browser asks for no supported language on a default install', function () {
    $this->get(route('login'), ['Accept-Language' => 'de-DE'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

test('an unsupported language is rejected', function () {
    $this->patch(route('locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
});

test('the shared user exposes only what the pages need', function () {
    $user = User::factory()->create(['name' => 'Ada Example', 'email' => 'ada@example.com']);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', ['name' => 'Ada Example', 'email' => 'ada@example.com'])
            ->has('locale'));
});
