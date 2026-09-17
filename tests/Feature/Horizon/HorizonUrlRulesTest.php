<?php

use App\Data\Applications\EnvironmentFormData;
use Illuminate\Support\Facades\Validator;

function horizonUrlErrors(string $url): array
{
    return Validator::make(['horizonUrl' => $url], ['horizonUrl' => EnvironmentFormData::horizonUrlRules()])
        ->errors()
        ->get('horizonUrl');
}

test('a horizon url with a query string or a fragment is refused', function (string $url) {
    expect(horizonUrlErrors($url))->toBe(['Use the address of the Horizon dashboard, without a query string or a fragment.']);
})->with([
    'query' => 'https://shop.example.com/horizon?x=1',
    'fragment' => 'https://shop.example.com/horizon#/dashboard',
    'both' => 'https://shop.example.com/horizon/?x=1#top',
    'empty query' => 'https://shop.example.com/horizon?',
    'empty fragment' => 'https://shop.example.com/horizon#',
]);

test('a plain horizon url passes', function (string $url) {
    expect(horizonUrlErrors($url))->toBe([]);
})->with([
    'dashboard' => 'https://shop.example.com/horizon',
    'with a port and a slash' => 'http://horizon.internal:8080/horizon/',
]);

test('the new rule sits next to the credentials rule, which still applies', function () {
    expect(horizonUrlErrors('https://monitor:secret@shop.example.com/horizon?x=1'))->toBe([
        'Put the credentials in the basic-auth fields, not in the URL.',
        'Use the address of the Horizon dashboard, without a query string or a fragment.',
    ]);
});
