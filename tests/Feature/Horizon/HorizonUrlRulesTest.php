<?php

use App\Data\Applications\EnvironmentFormData;
use App\Externals\Http\Dns\Resolver;
use Illuminate\Support\Facades\Validator;

function horizonUrlErrors(string $url): array
{
    return Validator::make(['horizonUrl' => $url], ['horizonUrl' => EnvironmentFormData::horizonUrlRules()])
        ->errors()
        ->get('horizonUrl');
}

test('a horizon url with a query string or a fragment is refused', function (string $url) {
    expect(horizonUrlErrors($url))->toBe(['The Horizon URL cannot have a query string or a fragment.']);
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

test('the credentials are judged first, and one message is enough', function () {
    expect(horizonUrlErrors('https://monitor:secret@shop.example.com/horizon?x=1'))
        ->toBe(['Put the credentials in the basic-auth fields, not in the Horizon URL.']);
});

test('an address on a network the panel never calls is refused', function (string $url) {
    expect(horizonUrlErrors($url))->toBe(['This address points to a network the panel does not call.']);
})->with([
    'the metadata address' => 'http://169.254.169.254/horizon',
    'the metadata name' => 'http://metadata.google.internal/horizon',
]);

test('a mapped metadata address is refused too', function () {
    expect(horizonUrlErrors('http://[::ffff:169.254.169.254]/horizon'))
        ->toContain('This address points to a network the panel does not call.');
});

test('a private address is refused only when private networks are blocked', function (bool $blocked) {
    config(['horizon-watch.block_private_networks' => $blocked]);

    expect(horizonUrlErrors('http://10.0.0.5/horizon'))
        ->toBe($blocked ? ['This address points to a network the panel does not call.'] : []);
})->with([true, false]);

test('a host name is never resolved while validating', function () {
    $this->mock(Resolver::class)->shouldNotReceive('resolve');

    expect(horizonUrlErrors('https://unresolvable.invalid/horizon'))->toBe([]);
});

test('an address that is not http gets the url message only', function () {
    expect(horizonUrlErrors('ftp://shop.example.com/horizon'))->toHaveCount(1);
});
