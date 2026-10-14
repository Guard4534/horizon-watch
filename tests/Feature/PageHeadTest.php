<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every page tells the browser it is dark, private and installable', function () {
    User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="color-scheme" content="dark">', false)
        ->assertSee('<meta name="theme-color" content="#161826">', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);

    expect(file_get_contents(resource_path('css/nocturne.css')))->toMatch('/:root \{\s*color-scheme: dark;/');
});

test('crawlers are asked to stay out of the whole panel', function () {
    expect(file_get_contents(public_path('robots.txt')))->toBe("User-agent: *\nDisallow: /\n");
});

test('the web app manifest carries the runtime name of the instance and icons that exist', function () {
    config(['app.name' => 'Ops Watch']);

    $response = $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertCookieMissing(config('session.cookie'))
        ->assertJsonPath('name', 'Ops Watch')
        ->assertJsonPath('short_name', 'Ops Watch')
        ->assertJsonPath('start_url', '/')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('theme_color', '#161826');

    $icons = $response->json('icons');

    expect($icons)->toHaveCount(3)
        ->and(collect($icons)->where('purpose', 'maskable'))->toHaveCount(1);

    foreach ($icons as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));

        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

test('the icons are the brand mark of the panel, not the starter kit logo', function () {
    $svg = file_get_contents(public_path('favicon.svg'));

    expect($svg)->toContain('#9184d9')->toContain('#161826')->not->toContain('#FF2D20');

    expect(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180)
        ->and(file_get_contents(public_path('favicon.ico'), length: 4))->toBe("\x00\x00\x01\x00");
});

test('the tab title and the brand mark use the runtime APP_NAME, not one fixed at build time', function () {
    expect(file_get_contents(resource_path('js/app.ts')))
        ->toContain('page.props.name')
        ->not->toContain('VITE_APP_NAME');

    expect(file_get_contents(resource_path('js/components/nocturne/BrandMark.vue')))
        ->toContain('{{ page.props.name }}')
        ->not->toContain('Horizon Watch');

    expect(file_get_contents(base_path('.env.example')))->not->toContain('VITE_APP_NAME');
});

test('pages and error pages share the runtime name of the instance', function () {
    config(['app.name' => 'Ops Watch']);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('name', 'Ops Watch'));

    $this->get('/nothing/lives/here')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('name', 'Ops Watch'));
});
