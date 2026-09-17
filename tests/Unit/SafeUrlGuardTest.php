<?php

use App\Enums\ReadingError;
use App\Externals\Horizon\Dns\SystemResolver;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\ResolvedTarget;
use App\Externals\Horizon\SafeUrlGuard;
use Tests\Fixtures\Horizon\FakeResolver;
use Tests\Support\TraceArguments;

// Runs without the application: the guard gets its resolver and its
// private-network switch through the constructor.

/**
 * @param  array<string, list<string>>  $answers
 */
function guardFor(array $answers, bool $blockPrivateNetworks = false, ?FakeResolver &$resolver = null): SafeUrlGuard
{
    $resolver = new FakeResolver($answers);

    return new SafeUrlGuard($resolver, $blockPrivateNetworks);
}

function guardRefusal(SafeUrlGuard $guard, string $url): ?ReadingError
{
    try {
        $guard->check($url);
    } catch (HorizonReadFailed $exception) {
        return $exception->reason;
    }

    return null;
}

dataset('always blocked addresses', [
    'aws metadata' => '169.254.169.254',
    'link-local start' => '169.254.0.1',
    'link-local end' => '169.254.255.254',
    'ipv6 link-local' => 'fe80::1',
    'ipv6 link-local upper' => 'febf:ffff::1',
    'aws ipv6 metadata' => 'fd00:ec2::254',
    'alibaba metadata' => '100.100.100.200',
    'this network' => '0.0.0.0',
    'this network range' => '0.1.2.3',
    'ipv4 multicast' => '224.0.0.1',
    'ipv4 multicast end' => '239.255.255.250',
    'ipv6 multicast' => 'ff02::1',
    'ipv6 unspecified' => '::',
    'mapped metadata dotted' => '::ffff:169.254.169.254',
    'mapped metadata hex' => '::ffff:a9fe:a9fe',
]);

dataset('private addresses', [
    'loopback' => '127.0.0.1',
    'loopback range' => '127.1.2.3',
    'ipv6 loopback' => '::1',
    'ten' => '10.0.0.5',
    'one seven two start' => '172.16.0.1',
    'one seven two end' => '172.31.255.254',
    'one nine two' => '192.168.1.10',
    'unique local' => 'fc00::1',
    'unique local fd' => 'fd12:3456::1',
    'mapped private' => '::ffff:10.0.0.5',
]);

dataset('public addresses', [
    'ipv4 documentation' => '203.0.113.10',
    'ipv6 documentation' => '2001:db8::10',
    'just outside one seven two' => '172.32.0.1',
    'next to alibaba metadata' => '100.100.100.201',
    'next to link-local' => '169.255.0.1',
]);

test('an address in an always-blocked range is refused', function (string $ip) {
    expect(guardRefusal(guardFor(['horizon.example.com' => [$ip]]), 'https://horizon.example.com/horizon'))
        ->toBe(ReadingError::Blocked);
})->with('always blocked addresses');

test('an always-blocked address stays refused when private networks are blocked too', function (string $ip) {
    expect(guardRefusal(guardFor(['horizon.example.com' => [$ip]], blockPrivateNetworks: true), 'https://horizon.example.com/horizon'))
        ->toBe(ReadingError::Blocked);
})->with('always blocked addresses');

test('private addresses are allowed by default', function (string $ip) {
    expect(guardRefusal(guardFor(['horizon.internal' => [$ip]]), 'http://horizon.internal/horizon'))->toBeNull();
})->with('private addresses');

test('private addresses are refused when the switch is on', function (string $ip) {
    expect(guardRefusal(guardFor(['horizon.internal' => [$ip]], blockPrivateNetworks: true), 'http://horizon.internal/horizon'))
        ->toBe(ReadingError::Blocked);
})->with('private addresses');

test('public addresses pass with and without the switch', function (string $ip, bool $blockPrivateNetworks) {
    expect(guardRefusal(guardFor(['horizon.example.com' => [$ip]], $blockPrivateNetworks), 'https://horizon.example.com/horizon'))
        ->toBeNull();
})->with('public addresses')->with([false, true]);

test('a name that resolves to a public address and a metadata address is refused', function () {
    $guard = guardFor(['mixed.example.com' => ['203.0.113.10', '2001:db8::10', '169.254.169.254']]);

    expect(guardRefusal($guard, 'https://mixed.example.com/horizon'))->toBe(ReadingError::Blocked);
});

test('only http and https are allowed', function (string $url) {
    $guard = guardFor(['shop.example.com' => ['203.0.113.10']], resolver: $resolver);

    expect(guardRefusal($guard, $url))->toBe(ReadingError::Blocked)
        ->and($resolver->asked)->toBe([]);
})->with([
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://shop.example.com/horizon',
    'gopher' => 'gopher://shop.example.com:70/_horizon',
    'dict' => 'dict://shop.example.com:11211/stats',
    'no scheme' => 'shop.example.com/horizon',
    'scheme relative' => '//shop.example.com/horizon',
    'empty host' => 'http:///horizon',
    'not a url' => 'http://',
]);

test('the scheme is compared without regard to case', function () {
    expect(guardRefusal(guardFor(['shop.example.com' => ['203.0.113.10']]), 'HTTPS://shop.example.com/horizon'))->toBeNull();
});

test('metadata names are refused before any lookup', function (string $url) {
    $guard = guardFor([
        'metadata.google.internal' => ['203.0.113.10'],
        'metadata' => ['203.0.113.10'],
    ], resolver: $resolver);

    expect(guardRefusal($guard, $url))->toBe(ReadingError::Blocked)
        ->and($resolver->asked)->toBe([]);
})->with([
    'gcp' => 'http://metadata.google.internal/computeMetadata/v1',
    'gcp upper case' => 'http://METADATA.Google.Internal/horizon',
    'gcp trailing dot' => 'http://metadata.google.internal./horizon',
    'short name' => 'http://metadata/horizon',
    'short name with port' => 'http://metadata:80/horizon',
]);

test('a name that does not resolve is unreachable, not blocked', function () {
    expect(guardRefusal(guardFor([]), 'https://missing.example.com/horizon'))->toBe(ReadingError::Unreachable);
});

test('a resolver answer that is not an address is refused', function () {
    expect(guardRefusal(guardFor(['odd.example.com' => ['203.0.113.10', 'not-an-address']]), 'https://odd.example.com/horizon'))
        ->toBe(ReadingError::Blocked);
});

test('an address written in the url is checked like a resolved one', function (string $url, ?ReadingError $expected) {
    $guard = guardFor([
        '169.254.169.254' => ['169.254.169.254'],
        '::1' => ['::1'],
        '203.0.113.10' => ['203.0.113.10'],
    ], blockPrivateNetworks: true);

    expect(guardRefusal($guard, $url))->toBe($expected);
})->with([
    'metadata literal' => ['http://169.254.169.254/latest/meta-data', ReadingError::Blocked],
    'ipv6 loopback literal' => ['http://[::1]:8080/horizon', ReadingError::Blocked],
    'public literal' => ['http://203.0.113.10/horizon', null],
]);

test('credentials in the url do not change the host that is checked', function () {
    $guard = guardFor(['shop.example.com' => ['203.0.113.10'], 'metadata' => ['203.0.113.10']], resolver: $resolver);

    expect(guardRefusal($guard, 'http://metadata@shop.example.com/horizon'))->toBeNull()
        ->and($resolver->asked)->toBe(['shop.example.com']);
});

test('the resolved target carries host, port and the address to pin', function (string $url, array $answer, string $host, int $port, string $ip, string $curlResolve) {
    $target = guardFor(['shop.example.com' => $answer])->check($url);

    expect($target)->toBeInstanceOf(ResolvedTarget::class)
        ->and($target->host)->toBe($host)
        ->and($target->port)->toBe($port)
        ->and($target->ip)->toBe($ip)
        ->and($target->curlResolve())->toBe($curlResolve);
})->with([
    'https default port' => ['https://shop.example.com/horizon', ['203.0.113.10'], 'shop.example.com', 443, '203.0.113.10', 'shop.example.com:443:203.0.113.10'],
    'http default port' => ['http://shop.example.com/horizon', ['203.0.113.10'], 'shop.example.com', 80, '203.0.113.10', 'shop.example.com:80:203.0.113.10'],
    'explicit port' => ['http://shop.example.com:8080/horizon', ['203.0.113.10'], 'shop.example.com', 8080, '203.0.113.10', 'shop.example.com:8080:203.0.113.10'],
    'ipv6 in brackets' => ['https://shop.example.com/horizon', ['2001:db8::10'], 'shop.example.com', 443, '2001:db8::10', 'shop.example.com:443:[2001:db8::10]'],
    'ipv4 preferred' => ['https://shop.example.com/horizon', ['2001:db8::10', '203.0.113.10'], 'shop.example.com', 443, '203.0.113.10', 'shop.example.com:443:203.0.113.10'],
    'mapped pinned as ipv4' => ['https://shop.example.com/horizon', ['::ffff:203.0.113.10'], 'shop.example.com', 443, '203.0.113.10', 'shop.example.com:443:203.0.113.10'],
    'host lower-cased' => ['https://SHOP.example.com/horizon', ['203.0.113.10'], 'shop.example.com', 443, '203.0.113.10', 'shop.example.com:443:203.0.113.10'],
]);

test('the url, with its credentials, never reaches the exception', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        guardFor([])->check('https://monitor:correct-horse-battery@missing.example.com/horizon');
        $this->fail('The guard accepted a name that does not resolve.');
    } catch (HorizonReadFailed $exception) {
        expect($exception->getMessage())->toBe('unreachable')
            ->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('correct-horse')
            ->and((string) $exception)->not->toContain('monitor:')
            ->and(TraceArguments::ofAppFrames($exception))->not->toContain('correct-horse');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

test('the system resolver returns an address literal as it is', function (string $host, array $expected) {
    expect((new SystemResolver)->resolve($host))->toBe($expected);
})->with([
    'ipv4' => ['203.0.113.10', ['203.0.113.10']],
    'ipv6' => ['2001:db8::10', ['2001:db8::10']],
    'ipv6 in brackets' => ['[::1]', ['::1']],
]);

test('the system resolver reads the hosts file, not only dns', function () {
    expect((new SystemResolver)->resolve('localhost'))->toContain('127.0.0.1');
});

dataset('ipv6 forms of a metadata address', [
    'ipv4-compatible' => '::169.254.169.254',
    'ipv4-compatible hex' => '::a9fe:a9fe',
    'nat64' => '64:ff9b::a9fe:a9fe',
    'nat64 dotted' => '64:ff9b::169.254.169.254',
    'siit' => '::ffff:0:a9fe:a9fe',
    '6to4' => '2002:a9fe:a9fe::1',
    'teredo client' => '2001:0:4136:e378:8000:63bf:5601:5601',
    'local-use nat64 prefix' => '64:ff9b:1::a9fe:a9fe',
    'ipv4-compatible this network' => '::0.0.0.2',
]);

test('an ipv6 address that leads to a blocked ipv4 address is refused', function (string $ip, bool $blockPrivateNetworks) {
    expect(guardRefusal(guardFor(['horizon.example.com' => [$ip]], $blockPrivateNetworks), 'https://horizon.example.com/horizon'))
        ->toBe(ReadingError::Blocked);
})->with('ipv6 forms of a metadata address')->with([false, true]);

test('an ipv6 literal that leads to a blocked ipv4 address is refused', function (string $url, bool $blockPrivateNetworks) {
    expect(guardRefusal(new SafeUrlGuard(new SystemResolver, $blockPrivateNetworks), $url))->toBe(ReadingError::Blocked);
})->with([
    'ipv4-compatible' => 'http://[::169.254.169.254]/horizon',
    'nat64' => 'http://[64:ff9b::a9fe:a9fe]/horizon',
    '6to4' => 'http://[2002:a9fe:a9fe::1]/horizon',
    'siit' => 'http://[::ffff:0:a9fe:a9fe]/horizon',
])->with([false, true]);

test('an ipv6 address that leads to a private ipv4 address follows the switch', function (string $ip) {
    $url = 'https://horizon.example.com/horizon';

    expect(guardRefusal(guardFor(['horizon.example.com' => [$ip]]), $url))->toBeNull()
        ->and(guardRefusal(guardFor(['horizon.example.com' => [$ip]], blockPrivateNetworks: true), $url))->toBe(ReadingError::Blocked);
})->with([
    'ipv4-compatible' => '::10.0.0.5',
    'nat64' => '64:ff9b::a00:5',
    '6to4' => '2002:a00:5::1',
    'siit' => '::ffff:0:c0a8:10a',
]);

test('an ipv6 address that leads to a public ipv4 address passes and is pinned as itself', function () {
    $target = guardFor(['horizon.example.com' => ['64:ff9b::cb00:710a']], blockPrivateNetworks: true)
        ->check('https://horizon.example.com/horizon');

    expect($target->ip)->toBe('64:ff9b::cb00:710a')
        ->and($target->curlResolve())->toBe('horizon.example.com:443:[64:ff9b::cb00:710a]');
});

test('the ipv6 loopback is still judged as loopback, not as an embedded address', function () {
    expect(guardRefusal(guardFor(['horizon.internal' => ['::1']]), 'http://horizon.internal/horizon'))->toBeNull();
});

test('a host that is not plain ascii, or not a canonical address, is refused before any lookup', function (string $url) {
    $guard = guardFor(['shop.example.com' => ['203.0.113.10']], resolver: $resolver);

    expect(guardRefusal($guard, $url))->toBe(ReadingError::Blocked)
        ->and($resolver->asked)->toBe([]);
})->with([
    'raw utf-8' => "http://sh\u{00f6}p.example.com/horizon",
    'percent-encoded dot' => 'http://shop%2Eexample.com/horizon',
    'percent-encoded host' => 'http://%73hop.example.com/horizon',
    'ipv6 zone' => 'http://[fe80::1%25eth0]/horizon',
    'not an ipv6 literal' => 'http://[shop.example.com]/horizon',
    'decimal ipv4' => 'http://2130706433/horizon',
    'octal ipv4' => 'http://0177.0.0.1/horizon',
    'short ipv4' => 'http://127.1/horizon',
    'hex ipv4' => 'http://0x7f000001/horizon',
    'hex octet' => 'http://0x7f.0.0.1/horizon',
    'leading zero' => 'http://127.0.0.01/horizon',
    'five parts' => 'http://1.2.3.4.5/horizon',
    'numeric last label' => 'http://shop.example.123/horizon',
    'dotted quad with a trailing dot' => 'http://10.0.0.5./horizon',
    'space in the host' => 'http://shop example.com/horizon',
]);

test('a url that is not printable ascii is refused', function (string $url) {
    expect(guardRefusal(guardFor(['shop.example.com' => ['203.0.113.10']]), $url))->toBe(ReadingError::Blocked);
})->with([
    'invalid utf-8 in the path' => "https://shop.example.com/horizon\xff",
    'utf-8 in the path' => "https://shop.example.com/h\u{00f6}rizon",
    'space in the path' => 'https://shop.example.com/hori zon',
    'newline' => "https://shop.example.com/horizon\n",
    'nul' => "https://shop.example.com/horizon\0",
]);

test('ordinary host names still pass', function (string $url, string $host) {
    $target = guardFor([$host => ['203.0.113.10']])->check($url);

    expect($target->host)->toBe($host);
})->with([
    'punycode' => ['https://xn--shp-sna.example.com/horizon', 'xn--shp-sna.example.com'],
    'container service name' => ['http://horizon_app:8080/horizon', 'horizon_app'],
    'digits inside labels' => ['https://app2.123abc.example.com/horizon', 'app2.123abc.example.com'],
    'single label' => ['http://horizon/horizon', 'horizon'],
]);
