<?php

use App\Enums\ReadingError;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;

// A real server on the loopback, through the real guard, resolver and
// cURL: body caps, stalls and concurrency happen on the wire, where
// Http::fake does not go.

beforeAll(function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $counter = (string) tempnam(sys_get_temp_dir(), 'hostile-horizon-');

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__, 2).'/Fixtures/Horizon/hostile-server.php'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '16', 'HOSTILE_COUNTER_FILE' => $counter],
    );

    $deadline = microtime(true) + 5;

    while (($probe = @fsockopen('127.0.0.1', $port, timeout: 0.1)) === false && microtime(true) < $deadline) {
        usleep(50_000);
    }

    if ($probe !== false) {
        fclose($probe);
    }

    $GLOBALS['hostileHorizon'] = ['process' => $process, 'port' => $port, 'counter' => $counter];
});

afterAll(function () {
    ['process' => $process, 'counter' => $counter] = $GLOBALS['hostileHorizon'];

    // The array form of proc_open runs php itself, not a shell, and php -S
    // takes its worker children down when it gets SIGTERM.
    proc_terminate($process);
    proc_close($process);
    @unlink($counter);
});

beforeEach(function () {
    config(['horizon-watch.http_timeout_seconds' => 2]);
    file_put_contents($GLOBALS['hostileHorizon']['counter'], '0 0 0');
});

function hostileTarget(string $scenario): HorizonTarget
{
    return new HorizonTarget('http://127.0.0.1:'.$GLOBALS['hostileHorizon']['port'].'/'.$scenario.'/horizon', null, null);
}

function hostileFailure(Closure $call): ?ReadingError
{
    try {
        $call();
    } catch (HorizonReadFailed $exception) {
        return $exception->reason;
    }

    return null;
}

test('the server answers a valid horizon', function () {
    expect(app(HorizonReader::class)->probe(hostileTarget('valid'))->status)->toBe('running');
});

test('an answer just under 2 MiB is read', function () {
    expect(app(HorizonReader::class)->probe(hostileTarget('just-under-the-cap'))->status)->toBe('running');
});

test('an answer that announces more than 2 MiB is refused at once', function () {
    $started = microtime(true);

    expect(hostileFailure(fn () => app(HorizonReader::class)->probe(hostileTarget('announced-too-large'))))->toBe(ReadingError::NotHorizon)
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
});

test('an answer that never ends is cut at 2 MiB without buffering it', function () {
    // Production's limit: without the cap this dies here instead of taking
    // the machine's memory.
    $limit = ini_set('memory_limit', '256M');
    memory_reset_peak_usage();
    $memory = memory_get_usage(true);
    $started = microtime(true);

    expect(hostileFailure(fn () => app(HorizonReader::class)->probe(hostileTarget('endless'))))->toBe(ReadingError::NotHorizon)
        ->and(hostileFailure(fn () => app(HorizonReader::class)->read(hostileTarget('endless'))))->toBe(ReadingError::NotHorizon)
        ->and(microtime(true) - $started)->toBeLessThan(1.5)
        ->and(memory_get_peak_usage(true) - $memory)->toBeLessThan(32 * 1024 * 1024);

    ini_set('memory_limit', (string) $limit);
});

test('a timeout in the middle of the body is unreachable', function () {
    $started = microtime(true);

    expect(hostileFailure(fn () => app(HorizonReader::class)->probe(hostileTarget('stalls-mid-body'))))->toBe(ReadingError::Unreachable)
        ->and(microtime(true) - $started)->toBeLessThan(4.0);
});

test('runtime is asked for 100 queues, at most 10 at a time', function () {
    $reading = app(HorizonReader::class)->read(hostileTarget('many-queues'));

    [$running, $peak, $total] = array_map('intval', explode(' ', (string) file_get_contents($GLOBALS['hostileHorizon']['counter'])));

    expect($reading->queueRuntimes)->toHaveCount(100)
        ->and($reading->queueRuntimes)->toHaveKey('q150')
        ->and($reading->queueRuntimes)->not->toHaveKey('q50')
        ->and($total)->toBe(100)
        ->and($running)->toBe(0)
        ->and($peak)->toBeGreaterThan(1)
        ->and($peak)->toBeLessThanOrEqual(10);
});
