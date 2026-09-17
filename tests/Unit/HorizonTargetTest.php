<?php

use App\Externals\Horizon\HorizonTarget;

test('the api url is built from the dashboard url however it was pasted', function (string $dashboardUrl) {
    $target = new HorizonTarget(dashboardUrl: $dashboardUrl, username: null, password: null);

    expect($target->apiUrl('stats'))->toBe('https://shop.example.com/horizon/api/stats')
        ->and($target->apiUrl('/metrics/queues/default'))->toBe('https://shop.example.com/horizon/api/metrics/queues/default');
})->with([
    'dashboard' => 'https://shop.example.com/horizon',
    'trailing slash' => 'https://shop.example.com/horizon/',
    'api pasted' => 'https://shop.example.com/horizon/api',
    'api pasted with a slash' => 'https://shop.example.com/horizon/api/',
]);

test('a dashboard path that only contains "api" is left alone', function () {
    $target = new HorizonTarget(dashboardUrl: 'https://api.example.com/apiary', username: null, password: null);

    expect($target->apiUrl('stats'))->toBe('https://api.example.com/apiary/api/stats');
});

test('basic auth needs both halves', function (?string $username, ?string $password, bool $expected) {
    expect((new HorizonTarget('https://shop.example.com/horizon', $username, $password))->hasBasicAuth())->toBe($expected);
})->with([
    'both' => ['monitor', 'correct-horse-battery', true],
    'no password' => ['monitor', null, false],
    'empty password' => ['monitor', '', false],
    'no username' => [null, 'correct-horse-battery', false],
    'neither' => [null, null, false],
]);

test('no way of printing a target shows the password', function (Closure $print) {
    $target = new HorizonTarget(
        dashboardUrl: 'https://shop.example.com/horizon',
        username: 'monitor',
        password: 'correct-horse-battery',
    );

    $output = $print($target);

    expect($output)->not->toContain('correct-horse-battery')
        ->and($output)->toContain('monitor')
        ->and($target->password())->toBe('correct-horse-battery');
})->with([
    'var_dump' => function (HorizonTarget $target): string {
        ob_start();
        var_dump($target);

        return (string) ob_get_clean();
    },
    'print_r' => fn (HorizonTarget $target): string => print_r($target, true),
    'json_encode' => fn (HorizonTarget $target): string => (string) json_encode($target),
]);

test('an exception trace does not show the password', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    $target = new HorizonTarget('https://shop.example.com/horizon', 'monitor', 'correct-horse-battery');

    try {
        $exception = (fn (HorizonTarget $target) => new RuntimeException('boom'))($target);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    $frame = $exception->getTrace()[0];

    expect($frame['args'] ?? [])->toBe([$target])
        ->and($exception->getTraceAsString())->not->toContain('correct-horse-battery')
        ->and(print_r($frame, true))->not->toContain('correct-horse-battery');
});

test('credentials pasted into the dashboard url are masked too', function () {
    $target = new HorizonTarget(
        dashboardUrl: 'https://monitor:pasted-secret@shop.example.com/horizon',
        username: null,
        password: null,
    );

    expect(print_r($target, true))->not->toContain('pasted-secret')
        ->and((string) json_encode($target))->not->toContain('pasted-secret')
        ->and(print_r($target, true))->toContain('shop.example.com/horizon');
});

test('a target refuses to be serialized', function () {
    serialize(new HorizonTarget('https://shop.example.com/horizon', 'monitor', 'correct-horse-battery'));
})->throws(LogicException::class);
