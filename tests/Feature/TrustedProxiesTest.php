<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('trusted-proxy-probe', fn () => ['url' => url('/login')]);
});

test('a request forwarded by a tls terminating proxy produces https urls', function () {
    $url = $this->get('trusted-proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('https://')->toEndWith('/login');
});

test('a request that reaches the panel directly keeps its own scheme', function () {
    $url = $this->get('trusted-proxy-probe')
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('http://')->toEndWith('/login');
});

test('a trusted proxy cannot change the host the panel answers for', function () {
    $url = $this->get('trusted-proxy-probe', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('https://')
        ->and(parse_url($url, PHP_URL_HOST))->toBe(parse_url(config()->string('app.url'), PHP_URL_HOST));
});

test('a trusted proxy still sets the client address', function () {
    Route::get('trusted-proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $ip = $this->get('trusted-proxy-ip', ['X-Forwarded-For' => '198.51.100.7'])
        ->assertOk()
        ->json('ip');

    expect($ip)->toBe('198.51.100.7');
});

test('forwarded headers from outside the private ranges are ignored', function () {
    $url = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('trusted-proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->json('url');

    expect($url)->toStartWith('http://');
});

test('the session cookie is marked secure on a request a trusted proxy served over tls', function () {
    Route::get('trusted-proxy-probe', fn () => ['url' => url('/login')])
        ->middleware('web');

    $response = $this->get('trusted-proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});
