<?php

use App\Support\HorizonUrl;

test('a Horizon URL is tidied the same way however it was pasted', function (string $pasted, string $stored) {
    expect(HorizonUrl::normalize($pasted))->toBe($stored);
})->with([
    'already tidy' => ['https://shop.example.com/horizon', 'https://shop.example.com/horizon'],
    'spaces and a trailing slash' => ['  https://shop.example.com/horizon/ ', 'https://shop.example.com/horizon'],
    'capitals in scheme and host, not in the path' => ['HTTPS://Shop.Example.COM/Ops/Horizon', 'https://shop.example.com/Ops/Horizon'],
    'the api path' => ['https://shop.example.com/horizon/api', 'https://shop.example.com/horizon'],
    'the api path with a slash' => ['https://shop.example.com/horizon/api/', 'https://shop.example.com/horizon'],
    'an api endpoint' => ['https://shop.example.com:8443/ops/horizon/api/masters', 'https://shop.example.com:8443/ops/horizon'],
    'a path that only starts with api' => ['https://api.example.com/apiary', 'https://api.example.com/apiary'],
    'horizon served at /api is kept' => ['https://shop.example.com/api', 'https://shop.example.com/api'],
    'an api segment inside the dashboard path is kept' => ['https://shop.example.com/tools/api/horizon', 'https://shop.example.com/tools/api/horizon'],
    'an api endpoint after an api segment' => ['https://shop.example.com/api/horizon/api/jobs/failed', 'https://shop.example.com/api/horizon'],
    'the bare host' => ['https://shop.example.com/', 'https://shop.example.com'],
    'a query is left for the validator' => ['https://shop.example.com/horizon/api?x=1', 'https://shop.example.com/horizon/api?x=1'],
    'credentials are left for the validator' => ['https://Monitor:Secret@Shop.example.com/horizon', 'https://Monitor:Secret@shop.example.com/horizon'],
]);

test('the host of a URL is read in lower case, or not at all', function (string $url, ?string $host) {
    expect(HorizonUrl::host($url))->toBe($host);
})->with([
    'with a port' => ['https://Shop.Example.com:8443/horizon', 'shop.example.com'],
    'plain' => ['http://staging.shop.example.com', 'staging.shop.example.com'],
    'not a url' => ['shop.example.com/horizon', null],
    'empty' => ['', null],
]);
