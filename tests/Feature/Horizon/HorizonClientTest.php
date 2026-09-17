<?php

use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\Dns\Resolver;
use App\Externals\Horizon\Dns\SystemResolver;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonClient;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\Horizon\FakeResolver;

const CLIENT_TEST_PASSWORD = 'correct-horse-battery';

function clientFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Horizon/'.$name));
}

function clientJson(string $name): Closure
{
    return fn () => Http::response(clientFixture($name), 200, ['Content-Type' => 'application/json']);
}

/**
 * Fakes every Horizon endpoint from the fixtures and records, per API path,
 * the Guzzle options each request was sent with.
 *
 * @param  array<string, Closure>  $overrides  keyed by API path, e.g. "stats" or "metrics/queues/default"
 * @return ArrayObject<string, array<string, mixed>>
 */
function fakeHorizonApi(array $overrides = []): ArrayObject
{
    $sent = new ArrayObject;

    $routes = $overrides + [
        'stats' => clientJson('stats.json'),
        'masters' => clientJson('masters.json'),
        'workload' => clientJson('workload.json'),
        'jobs/failed' => clientJson('failed.json'),
        'jobs/pending' => clientJson('pending.json'),
        'metrics/queues/default' => clientJson('queue-metrics.json'),
        'metrics/queues/emails' => fn () => Http::response('[]', 200, ['Content-Type' => 'application/json']),
        'metrics/queues/reports' => fn () => Http::response('[]', 200, ['Content-Type' => 'application/json']),
    ];

    Http::preventStrayRequests();
    Http::fake(function (Request $request, array $options) use ($sent, $routes) {
        $path = rawurldecode(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/horizon/api/'));
        $sent[$path] = $options;

        return $routes[$path] ?? Http::response('Not found', 404);
    });

    return $sent;
}

function clientTarget(?string $username = 'monitor', ?string $password = CLIENT_TEST_PASSWORD, string $url = 'https://shop.example.com/horizon'): HorizonTarget
{
    return new HorizonTarget(dashboardUrl: $url, username: $username, password: $password);
}

function clientFailure(Closure $call): HorizonReadFailed
{
    try {
        $call();
    } catch (HorizonReadFailed $exception) {
        return $exception;
    }

    throw new RuntimeException('The client did not fail.');
}

beforeEach(function () {
    $this->resolver = new FakeResolver([
        'shop.example.com' => ['203.0.113.10'],
        'metadata.example.com' => ['203.0.113.11', '169.254.169.254'],
        'intranet.example.com' => ['10.0.0.5'],
    ]);

    $this->app->instance(Resolver::class, $this->resolver);
});

test('the container hands out the real client and the system resolver', function () {
    $this->app->forgetInstance(Resolver::class);

    expect(app(HorizonReader::class))->toBeInstanceOf(HorizonClient::class)
        ->and(app(Resolver::class))->toBeInstanceOf(SystemResolver::class);
});

test('a complete reading is mapped field by field', function () {
    fakeHorizonApi();

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->stats->status)->toBe('running')
        ->and($reading->stats->jobsPerMinute)->toBe(42)
        ->and($reading->stats->failedJobs)->toBe(12)
        ->and($reading->stats->processes)->toBe(7)
        ->and($reading->stats->pausedMasters)->toBe(1)
        ->and($reading->stats->wait)->toBe(['redis:reports' => 120])
        ->and($reading->stats->failedJobsPeriodMinutes)->toBe(10080)
        ->and($reading->latencyMs)->toBeInt()->toBeGreaterThanOrEqual(0);

    expect($reading->masters)->toEqual([
        new HorizonMaster(name: 'worker-1-a1b2', status: 'running', supervisors: [
            new HorizonSupervisor(name: 'worker-1-a1b2:supervisor-1', status: 'running', processes: ['redis:default' => 3, 'redis:emails' => 2]),
        ]),
        new HorizonMaster(name: 'worker-2-c3d4', status: 'paused', supervisors: [
            new HorizonSupervisor(name: 'worker-2-c3d4:supervisor-reports', status: 'paused', processes: ['redis:reports' => 2]),
            new HorizonSupervisor(name: 'worker-2-c3d4:supervisor-spare', status: 'inactive', processes: []),
        ]),
    ]);

    expect($reading->workload)->toEqual([
        new HorizonQueueLoad(name: 'default', length: 15, wait: 4, processes: 3),
        new HorizonQueueLoad(name: 'emails', length: 0, wait: 0, processes: 2),
        new HorizonQueueLoad(name: 'reports', length: 3, wait: 120, processes: 2),
    ]);

    expect($reading->failedJobs)->toHaveCount(2)
        ->and($reading->failedJobs[0])->toEqual(new HorizonFailedJob(
            name: 'App\Jobs\SendWelcomeEmail',
            queue: 'emails',
            exception: 'Symfony\Component\Mailer\Exception\TransportException: Connection to smtp.example.com:587 timed out',
            attempts: 3,
            failedAt: CarbonImmutable::createFromTimestamp(1789646100.4821),
        ))
        ->and($reading->failedJobs[0]->failedAt->toIso8601String())->toBe('2026-09-17T11:55:00+00:00')
        ->and($reading->failedJobs[1]->name)->toBe('App\Jobs\BuildQuarterlyReport')
        ->and($reading->failedJobs[1]->queue)->toBe('reports')
        ->and($reading->failedJobs[1]->attempts)->toBe(0)
        ->and($reading->failedJobs[1]->exception)->toStartWith('App\Exceptions\ReportFailed: The quarterly report')
        ->and(mb_strlen($reading->failedJobs[1]->exception))->toBe(200)
        ->and($reading->failedJobs[1]->exception)->not->toContain("\n")
        ->and($reading->failedJobs[1]->failedAt->getTimestamp())->toBe(1789645200);

    expect($reading->pendingJobs)->toEqual([
        new HorizonPendingJob(
            name: 'App\Jobs\BuildQuarterlyReport',
            queue: 'reports',
            status: 'reserved',
            reservedAt: CarbonImmutable::createFromTimestamp(1789645200.75),
        ),
        new HorizonPendingJob(name: 'App\Jobs\SyncInventory', queue: 'default', status: 'pending', reservedAt: null),
    ]);

    // Horizon already turns the snapshot runtime into seconds; queues
    // without snapshots are absent.
    expect($reading->queueRuntimes)->toBe(['default' => 1.25]);
});

test('the job payload never leaves the client', function () {
    fakeHorizonApi();

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect(serialize($reading))->not->toContain('payload-secret-value')
        ->and(print_r($reading, true))->not->toContain('payload-secret-value');
});

test('every request is pinned to the checked address and follows no redirect', function () {
    $sent = fakeHorizonApi();

    app(HorizonReader::class)->read(clientTarget());

    expect(array_keys($sent->getArrayCopy()))->toEqualCanonicalizing([
        'stats', 'masters', 'workload', 'jobs/failed', 'jobs/pending',
        'metrics/queues/default', 'metrics/queues/emails', 'metrics/queues/reports',
    ]);

    foreach ($sent as $options) {
        expect($options['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:203.0.113.10'])
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['connect_timeout'])->toBe(5)
            ->and($options['timeout'])->toBe(5)
            ->and($options['proxy'])->toBe(['no' => ['*']]);
    }

    expect($this->resolver->asked)->toBe(['shop.example.com']);
});

test('basic auth is sent only with both credentials', function (?string $username, ?string $password, ?string $header) {
    fakeHorizonApi();

    app(HorizonReader::class)->read(clientTarget($username, $password));

    Http::assertSentCount(8);
    Http::assertSent(fn (Request $request) => ($request->header('Authorization')[0] ?? null) === $header);
    Http::assertNotSent(fn (Request $request) => ($request->header('Authorization')[0] ?? null) !== $header);
})->with([
    'both' => ['monitor', CLIENT_TEST_PASSWORD, 'Basic '.base64_encode('monitor:'.CLIENT_TEST_PASSWORD)],
    'no password' => ['monitor', null, null],
    'empty password' => ['monitor', '', null],
    'no username' => [null, CLIENT_TEST_PASSWORD, null],
]);

test('the api path is built from the dashboard url and queue names are encoded', function () {
    $sent = fakeHorizonApi([
        'workload' => fn () => Http::response([['name' => 'reports/eu west', 'length' => 1, 'wait' => 0, 'processes' => 1]]),
        'metrics/queues/reports/eu west' => clientJson('queue-metrics.json'),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget(url: 'https://shop.example.com/horizon/api/'));

    expect($reading->queueRuntimes)->toBe(['reports/eu west' => 1.25]);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.example.com/horizon/api/metrics/queues/reports%2Feu%20west');
});

test('a failed main call fails the whole reading', function (string $path, Closure $response, ReadingError $expected) {
    fakeHorizonApi([$path => $response]);

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe($expected);
})->with(['stats', 'masters', 'workload'])->with([
    'dashboard html with 200' => [fn () => fn () => Http::response(clientFixture('dashboard.html'), 200, ['Content-Type' => 'text/html']), ReadingError::NotHorizon],
    '401' => [fn () => fn () => Http::response('Unauthorized', 401), ReadingError::Unauthorized],
    '403' => [fn () => fn () => Http::response('Forbidden', 403), ReadingError::Unauthorized],
    '404' => [fn () => fn () => Http::response('Not found', 404), ReadingError::NotHorizon],
    '302' => [fn () => fn () => Http::response('', 302, ['Location' => 'https://shop.example.com/login']), ReadingError::NotHorizon],
    '503' => [fn () => fn () => Http::response('Service unavailable', 503), ReadingError::Unreachable],
    '500' => [fn () => fn () => Http::response('Server error', 500), ReadingError::Unreachable],
    '429' => [fn () => fn () => Http::response('Too many requests', 429), ReadingError::Unreachable],
    'connection refused' => [fn () => Http::failedConnection('cURL error 7: Failed to connect to shop.example.com'), ReadingError::Unreachable],
    'truncated json' => [fn () => fn () => Http::response('{"status": "running", "jobsPer', 200, ['Content-Type' => 'application/json']), ReadingError::NotHorizon],
    'json of the wrong shape' => [fn () => fn () => Http::response(['message' => 'Hello'], 200), ReadingError::NotHorizon],
    'json scalar' => [fn () => fn () => Http::response('42', 200, ['Content-Type' => 'application/json']), ReadingError::NotHorizon],
]);

test('stats that are not what horizon sends are refused', function (array $stats) {
    fakeHorizonApi(['stats' => fn () => Http::response($stats)]);

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);
})->with([
    'unknown status' => [['status' => 'sleeping', 'jobsPerMinute' => 1, 'failedJobs' => 0, 'processes' => 1]],
    'status not a string' => [['status' => 1, 'jobsPerMinute' => 1, 'failedJobs' => 0, 'processes' => 1]],
    'missing processes' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 0]],
    'numeric string' => [['status' => 'running', 'jobsPerMinute' => 'many', 'failedJobs' => 0, 'processes' => 1]],
    'a list' => [[1, 2, 3]],
]);

test('masters and workload of the wrong shape are refused', function (string $path, mixed $body) {
    fakeHorizonApi([$path => fn () => Http::response($body)]);

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);
})->with([
    'master without supervisors' => ['masters', ['worker-1' => ['name' => 'worker-1', 'status' => 'running']]],
    'master that is a string' => ['masters', ['worker-1' => 'running']],
    'supervisors not a list' => ['masters', ['worker-1' => ['name' => 'worker-1', 'status' => 'running', 'supervisors' => 'none']]],
    'workload object' => ['workload', ['name' => 'default', 'length' => 1, 'wait' => 0, 'processes' => 1]],
    'queue without length' => ['workload', [['name' => 'default', 'wait' => 0, 'processes' => 1]]],
    'workload of strings' => ['workload', ['default']],
]);

test('the failed-jobs window falls back to a day when horizon does not state it', function (array $stats) {
    fakeHorizonApi(['stats' => fn () => Http::response($stats)]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->stats->failedJobsPeriodMinutes)->toBe(1440)
        ->and($reading->stats->failedJobs)->toBe(12);
})->with([
    'no periods' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1]],
    'periods without failed jobs' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['recentJobs' => 60]]],
    'periods not an object' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => 'weekly']],
    'window not a number' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 'week']]],
    'window of zero' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 0]]],
    'negative window' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => -60]]],
]);

test('any positive failed-jobs window horizon states is kept', function () {
    fakeHorizonApi(['stats' => fn () => Http::response([
        'status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 1440, 'recentJobs' => 60],
    ])]);

    expect(app(HorizonReader::class)->read(clientTarget())->stats->failedJobsPeriodMinutes)->toBe(1440);
});

test('a horizon without masters answers an empty list', function () {
    fakeHorizonApi([
        'stats' => clientJson('stats-inactive.json'),
        'masters' => fn () => Http::response('[]', 200, ['Content-Type' => 'application/json']),
        'workload' => fn () => Http::response('[]', 200, ['Content-Type' => 'application/json']),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->stats->status)->toBe('inactive')
        ->and($reading->stats->wait)->toBe([])
        ->and($reading->masters)->toBe([])
        ->and($reading->workload)->toBe([])
        ->and($reading->queueRuntimes)->toBe([]);

    Http::assertSentCount(5);
});

test('a paused horizon is read as paused', function () {
    fakeHorizonApi(['stats' => clientJson('stats-paused.json')]);

    expect(app(HorizonReader::class)->read(clientTarget())->stats->status)->toBe('paused');
});

test('a failed secondary call leaves only its own section empty', function (array $overrides, bool $failedMissing, bool $pendingMissing, array $runtimes) {
    fakeHorizonApi($overrides);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->failedJobs === null)->toBe($failedMissing)
        ->and($reading->pendingJobs === null)->toBe($pendingMissing)
        ->and($reading->queueRuntimes)->toBe($runtimes)
        ->and($reading->stats->jobsPerMinute)->toBe(42)
        ->and($reading->masters)->toHaveCount(2)
        ->and($reading->workload)->toHaveCount(3);
})->with([
    'failed jobs 500' => [fn () => ['jobs/failed' => fn () => Http::response('Server error', 500)], true, false, ['default' => 1.25]],
    'failed jobs html' => [fn () => ['jobs/failed' => fn () => Http::response(clientFixture('dashboard.html'))], true, false, ['default' => 1.25]],
    'failed jobs without a list' => [fn () => ['jobs/failed' => fn () => Http::response(['jobs' => 'none'])], true, false, ['default' => 1.25]],
    'pending jobs unreachable' => [fn () => ['jobs/pending' => Http::failedConnection()], false, true, ['default' => 1.25]],
    'pending jobs 401' => [fn () => ['jobs/pending' => fn () => Http::response('', 401)], false, true, ['default' => 1.25]],
    'metrics 500' => [fn () => ['metrics/queues/default' => fn () => Http::response('Server error', 500)], false, false, []],
    'metrics not a list' => [fn () => ['metrics/queues/default' => fn () => Http::response(['runtime' => 3])], false, false, []],
    'metrics without runtime' => [fn () => ['metrics/queues/default' => fn () => Http::response([['throughput' => 3]])], false, false, []],
]);

test('an address the guard refuses sends no request at all', function (string $url, ReadingError $expected) {
    config(['horizon-watch.block_private_networks' => true]);
    $sent = fakeHorizonApi();

    $read = clientFailure(fn () => app(HorizonReader::class)->read(clientTarget(url: $url)));
    $probe = clientFailure(fn () => app(HorizonReader::class)->probe(clientTarget(url: $url)));

    expect($read->reason)->toBe($expected)
        ->and($probe->reason)->toBe($expected)
        ->and($sent)->toHaveCount(0);
    Http::assertNothingSent();
})->with([
    'metadata among the answers' => ['https://metadata.example.com/horizon', ReadingError::Blocked],
    'private with the switch on' => ['http://intranet.example.com/horizon', ReadingError::Blocked],
    'scheme' => ['ftp://shop.example.com/horizon', ReadingError::Blocked],
    'name that does not resolve' => ['https://missing.example.com/horizon', ReadingError::Unreachable],
]);

test('no failure carries the url, the credentials or the body', function (Closure $overrides, string $url) {
    fakeHorizonApi($overrides());
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $target = clientTarget(url: $url);

        foreach ([fn () => app(HorizonReader::class)->read($target), fn () => app(HorizonReader::class)->probe($target)] as $call) {
            $exception = clientFailure($call);
            $printed = (string) $exception;

            expect($exception->getMessage())->toBe($exception->reason->value)
                ->and($exception->getPrevious())->toBeNull()
                ->and($printed)->not->toContain(CLIENT_TEST_PASSWORD)
                ->and($printed)->not->toContain('shop.example.com')
                ->and($printed)->not->toContain('body-secret-value')
                ->and(appFrameArguments($exception))->not->toContain(CLIENT_TEST_PASSWORD);
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
})->with([
    'connection error naming the host' => [fn () => ['stats' => Http::failedConnection('cURL error 28: timed out for https://monitor:'.CLIENT_TEST_PASSWORD.'@shop.example.com/horizon/api/stats')], 'https://shop.example.com/horizon'],
    'error body' => [fn () => ['masters' => fn () => Http::response('body-secret-value', 500)], 'https://shop.example.com/horizon'],
    'unexpected json body' => [fn () => ['stats' => fn () => Http::response(['token' => 'body-secret-value'])], 'https://shop.example.com/horizon'],
    'unauthorized' => [fn () => ['stats' => fn () => Http::response('body-secret-value', 401)], 'https://monitor:'.CLIENT_TEST_PASSWORD.'@shop.example.com/horizon'],
    'blocked with credentials in the url' => [fn () => [], 'https://monitor:'.CLIENT_TEST_PASSWORD.'@metadata.example.com/horizon'],
]);

test('probe reads only stats and masters', function () {
    $sent = fakeHorizonApi();

    $probe = app(HorizonReader::class)->probe(clientTarget());

    expect($probe->status)->toBe('running')
        ->and($probe->masterCount)->toBe(2)
        ->and($probe->latencyMs)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and(array_keys($sent->getArrayCopy()))->toEqualCanonicalizing(['stats', 'masters'])
        ->and($sent['stats']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:203.0.113.10']);

    Http::assertSentCount(2);
});

test('probe fails like a reading', function (string $path, Closure $response, ReadingError $expected) {
    fakeHorizonApi([$path => $response]);

    expect(clientFailure(fn () => app(HorizonReader::class)->probe(clientTarget()))->reason)->toBe($expected);
})->with([
    'stats html' => ['stats', fn () => fn () => Http::response(clientFixture('dashboard.html')), ReadingError::NotHorizon],
    'masters 401' => ['masters', fn () => fn () => Http::response('', 401), ReadingError::Unauthorized],
    'masters unreachable' => ['masters', fn () => Http::failedConnection(), ReadingError::Unreachable],
]);
