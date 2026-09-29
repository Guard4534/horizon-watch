<?php

use App\Actions\Monitoring\PollEnvironment;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonClient;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;
use App\Externals\Http\Dns\Resolver;
use App\Externals\Http\Dns\SystemResolver;
use App\Models\Environment;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\Horizon\FakeResolver;
use Tests\Support\TraceArguments;

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
 * @param  array<string, Closure>  $overrides
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

function clientRaw(string $json): Closure
{
    return fn () => Http::response($json, 200, ['Content-Type' => 'application/json']);
}

/**
 * @param  array<string, string>  $values
 */
function clientStats(array $values): Closure
{
    $stats = json_decode(clientFixture('stats.json'), true);
    $json = json_encode(array_diff_key($stats, $values));

    foreach ($values as $key => $value) {
        $json = substr($json, 0, -1).','.json_encode($key).':'.$value.'}';
    }

    return clientRaw($json);
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

test('the failed-jobs window falls back to horizon\'s own default of a week when it is not usable', function (array $stats) {
    fakeHorizonApi(['stats' => fn () => Http::response($stats)]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->stats->failedJobsPeriodMinutes)->toBe(10080)
        ->and($reading->stats->failedJobs)->toBe(12);
})->with([
    'no periods' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1]],
    'periods without failed jobs' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['recentJobs' => 60]]],
    'periods not an object' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => 'weekly']],
    'window not a number' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 'week']]],
    'window of zero' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 0]]],
    'negative window' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => -60]]],
    'null window, as horizon states it without trim keys' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => null]]],
    'window rounding to zero' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 0.4]]],
    'window past an integer column' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 3000000000]]],
    'window too large to cast' => [['status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => 1e30]]],
]);

test('a failed-jobs window that is not a finite number falls back to a week', function (string $window) {
    fakeHorizonApi(['stats' => clientStats(['periods' => '{"failedJobs":'.$window.'}'])]);

    expect(app(HorizonReader::class)->read(clientTarget())->stats->failedJobsPeriodMinutes)->toBe(10080);
})->with(['1e999', '"-1e999"', 'true']);

test('a usable failed-jobs window is kept as horizon states it', function (mixed $window, int $expected) {
    fakeHorizonApi(['stats' => fn () => Http::response([
        'status' => 'running', 'jobsPerMinute' => 1, 'failedJobs' => 12, 'processes' => 1, 'periods' => ['failedJobs' => $window],
    ])]);

    expect(app(HorizonReader::class)->read(clientTarget())->stats->failedJobsPeriodMinutes)->toBe($expected);
})->with([
    'one minute' => [1, 1],
    'a day' => [1440, 1440],
    'as a string' => ['60', 60],
    'fractional' => [59.6, 60],
    'the largest integer column value' => [2147483647, 2147483647],
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
                ->and(TraceArguments::ofAppFrames($exception))->not->toContain(CLIENT_TEST_PASSWORD);
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

test('a main-call value that is not a finite number fails as not horizon, and only that way', function (string $path, Closure $response) {
    fakeHorizonApi([$path => $response]);

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);

    if ($path === 'stats') {
        expect(clientFailure(fn () => app(HorizonReader::class)->probe(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);
    }
})->with([
    'infinite jobs per minute' => ['stats', fn () => clientStats(['jobsPerMinute' => '1e999'])],
    'negative infinite failed jobs' => ['stats', fn () => clientStats(['failedJobs' => '-1e999'])],
    'infinite processes as a string' => ['stats', fn () => clientStats(['processes' => '"1e999"'])],
    'boolean processes' => ['stats', fn () => clientStats(['processes' => 'true'])],
    'infinite wait of a queue' => ['workload', fn () => clientRaw('[{"name":"default","length":1,"wait":1e999,"processes":1}]')],
    'infinite length of a queue' => ['workload', fn () => clientRaw('[{"name":"default","length":"1e999","wait":1,"processes":1}]')],
]);

test('absurd numbers saturate into what an integer column holds', function () {
    fakeHorizonApi([
        'stats' => clientStats([
            'jobsPerMinute' => '1e20',
            'failedJobs' => '3000000000',
            'processes' => '-3',
            'pausedMasters' => '1e999',
            'wait' => '{"redis:default":1e999,"redis:emails":-5,"redis:reports":12.6,"redis:huge":"99999999999999999999"}',
            'periods' => '{"failedJobs":1e999}',
        ]),
        'workload' => clientRaw('[{"name":"default","length":"99999999999999999999","wait":-1,"processes":2.4}]'),
        'masters' => clientRaw('{"w":{"name":"w","status":"running","supervisors":[{"name":"w:s","status":"running","processes":{"redis:default":1e999,"redis:emails":1e12}}]}}'),
        'jobs/failed' => clientRaw('{"jobs":[{"name":"App\\\\Jobs\\\\A","queue":"default","failed_at":"1789646100","payload":{"attempts":1e20}}]}'),
        'metrics/queues/default' => clientRaw('[{"runtime":1e300}]'),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->stats->jobsPerMinute)->toBe(2147483647)
        ->and($reading->stats->failedJobs)->toBe(2147483647)
        ->and($reading->stats->processes)->toBe(0)
        ->and($reading->stats->pausedMasters)->toBe(0)
        ->and($reading->stats->wait)->toBe(['redis:emails' => 0, 'redis:reports' => 13, 'redis:huge' => 2147483647])
        ->and($reading->stats->failedJobsPeriodMinutes)->toBe(10080)
        ->and($reading->workload)->toEqual([new HorizonQueueLoad(name: 'default', length: 2147483647, wait: 0, processes: 2)])
        ->and($reading->masters[0]->supervisors[0]->processes)->toBe(['redis:emails' => 2147483647])
        ->and($reading->failedJobs[0]->attempts)->toBe(2147483647)
        ->and($reading->queueRuntimes)->toBe(['default' => 2147483647.0]);
});

test('a runtime that is not a finite number is simply absent', function (string $body, array $expected) {
    fakeHorizonApi(['metrics/queues/default' => clientRaw($body)]);

    expect(app(HorizonReader::class)->read(clientTarget())->queueRuntimes)->toBe($expected);
})->with([
    'infinite' => ['[{"runtime":1e999}]', []],
    'infinite string' => ['[{"runtime":"-1e999"}]', []],
    'negative' => ['[{"runtime":-2}]', ['default' => 0.0]],
    'only the last snapshot counts' => ['[{"runtime":1.5},{"runtime":1e999}]', []],
]);

test('a job with an unusable time is skipped and the others are kept', function () {
    fakeHorizonApi([
        'jobs/failed' => clientRaw('{"jobs":['
            .'{"name":"Far","queue":"default","failed_at":1e300},'
            .'{"name":"Infinite","queue":"default","failed_at":1e999},'
            .'{"name":"Negative","queue":"default","failed_at":-5},'
            .'{"name":"Text","queue":"default","failed_at":"yesterday"},'
            .'{"name":"Missing","queue":"default"},'
            .'{"name":"Kept","queue":"default","failed_at":"1789646100.5"}]}'),
        'jobs/pending' => clientRaw('{"jobs":['
            .'{"name":"Far","queue":"default","status":"reserved","reserved_at":"1e20"},'
            .'{"name":"Text","queue":"default","status":"reserved","reserved_at":"soon"},'
            .'{"name":"Waiting","queue":"default","status":"pending","reserved_at":null},'
            .'{"name":"Running","queue":"default","status":"reserved","reserved_at":1789645200}]}'),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect(array_map(fn (HorizonFailedJob $job) => $job->name, $reading->failedJobs))->toBe(['Kept'])
        ->and(array_map(fn (HorizonPendingJob $job) => $job->name, $reading->pendingJobs))->toBe(['Waiting', 'Running'])
        ->and($reading->pendingJobs[1]->reservedAt?->getTimestamp())->toBe(1789645200);
});

test('probe survives absurd numbers too', function () {
    fakeHorizonApi(['stats' => clientStats(['jobsPerMinute' => '1e20', 'processes' => '"99999999999999999999"'])]);

    expect(app(HorizonReader::class)->probe(clientTarget())->status)->toBe('running');
});

test('names are cut to 255 characters', function () {
    $long = str_repeat('é', 300);

    fakeHorizonApi([
        'masters' => fn () => Http::response([$long => ['name' => $long, 'status' => 'running', 'supervisors' => [['name' => $long, 'status' => $long, 'processes' => []]]]]),
        'workload' => fn () => Http::response([['name' => $long, 'length' => 1, 'wait' => 0, 'processes' => 1]]),
        'jobs/failed' => fn () => Http::response(['jobs' => [['name' => $long, 'queue' => $long, 'failed_at' => 1789646100]]]),
        'jobs/pending' => fn () => Http::response(['jobs' => [['name' => $long, 'queue' => $long, 'status' => $long, 'reserved_at' => null]]]),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget());
    $cut = str_repeat('é', 255);

    expect($reading->masters[0]->name)->toBe($cut)
        ->and($reading->masters[0]->supervisors[0]->name)->toBe($cut)
        ->and($reading->masters[0]->supervisors[0]->status)->toBe($cut)
        ->and($reading->workload[0]->name)->toBe($cut)
        ->and($reading->failedJobs[0]->name)->toBe($cut)
        ->and($reading->failedJobs[0]->queue)->toBe($cut)
        ->and($reading->pendingJobs[0]->name)->toBe($cut)
        ->and($reading->pendingJobs[0]->queue)->toBe($cut)
        ->and($reading->pendingJobs[0]->status)->toBe($cut);
});

test('masters, supervisors and jobs are kept up to 200 each', function () {
    $masters = ['m1' => ['name' => 'm1', 'status' => 'running', 'supervisors' => array_map(
        fn (int $i) => ['name' => "s{$i}", 'status' => 'running', 'processes' => []],
        range(1, 250),
    )]];

    foreach (range(2, 250) as $i) {
        $masters["m{$i}"] = ['name' => "m{$i}", 'status' => 'running', 'supervisors' => []];
    }

    $jobs = array_map(fn (int $i) => ['name' => "j{$i}", 'queue' => 'default', 'status' => 'pending', 'failed_at' => 1789646100], range(1, 250));

    fakeHorizonApi([
        'masters' => fn () => Http::response($masters),
        'jobs/failed' => fn () => Http::response(['jobs' => $jobs]),
        'jobs/pending' => fn () => Http::response(['jobs' => $jobs]),
    ]);

    $reading = app(HorizonReader::class)->read(clientTarget());

    expect($reading->masters)->toHaveCount(200)
        ->and($reading->masters[199]->name)->toBe('m200')
        ->and($reading->masters[0]->supervisors)->toHaveCount(200)
        ->and($reading->masters[0]->supervisors[199]->name)->toBe('s200')
        ->and($reading->failedJobs)->toHaveCount(200)
        ->and($reading->failedJobs[199]->name)->toBe('j200')
        ->and($reading->pendingJobs)->toHaveCount(200)
        ->and(app(HorizonReader::class)->probe(clientTarget())->masterCount)->toBe(200);
});

test('a master past the cap is still checked for its shape', function () {
    $masters = [];

    foreach (range(1, 200) as $i) {
        $masters["m{$i}"] = ['name' => "m{$i}", 'status' => 'running', 'supervisors' => []];
    }

    $masters['broken'] = 'running';

    fakeHorizonApi(['masters' => fn () => Http::response($masters)]);

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);
});

test('runtime is asked only for the 100 busiest queues', function () {
    $queues = array_map(fn (int $i) => ['name' => "q{$i}", 'length' => $i, 'wait' => 0, 'processes' => 1], range(1, 150));
    $sent = fakeHorizonApi(['workload' => fn () => Http::response($queues)]);

    app(HorizonReader::class)->read(clientTarget());

    $asked = array_values(array_filter(array_keys($sent->getArrayCopy()), fn (string $path) => str_starts_with($path, 'metrics/queues/')));

    expect($asked)->toHaveCount(100)
        ->and($asked)->toContain('metrics/queues/q150', 'metrics/queues/q51')
        ->and($asked)->not->toContain('metrics/queues/q50');
});

test('an answer larger than 2 MiB is not horizon, wherever it comes from', function (string $path, bool $fails) {
    fakeHorizonApi([$path => fn () => Http::response('['.str_repeat(' ', 2 * 1024 * 1024).']', 200, ['Content-Type' => 'application/json'])]);

    if ($fails) {
        expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget()))->reason)->toBe(ReadingError::NotHorizon);
    } else {
        expect(app(HorizonReader::class)->read(clientTarget())->failedJobs)->toBeNull();
    }
})->with([
    'stats' => ['stats', true],
    'workload' => ['workload', true],
    'failed jobs' => ['jobs/failed', false],
]);

test('a url guzzle cannot build is blocked and sends nothing', function (string $url) {
    $this->resolver = new FakeResolver(['shop.example.com' => ['203.0.113.10']]);
    $this->app->instance(Resolver::class, $this->resolver);
    fakeHorizonApi();

    expect(clientFailure(fn () => app(HorizonReader::class)->read(clientTarget(url: $url)))->reason)->toBe(ReadingError::Blocked)
        ->and(clientFailure(fn () => app(HorizonReader::class)->probe(clientTarget(url: $url)))->reason)->toBe(ReadingError::Blocked);

    Http::assertNothingSent();
})->with([
    'invalid utf-8 in the path' => "https://shop.example.com/horizon\xff",
    'utf-8 in the host' => "https://sh\u{00f6}p.example.com/horizon",
]);

test('a query string or a fragment saved before the rule does not reach the api path', function (string $url) {
    fakeHorizonApi();

    app(HorizonReader::class)->probe(clientTarget(url: $url));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.example.com/horizon/api/stats');
    Http::assertSentCount(2);
})->with([
    'query' => 'https://shop.example.com/horizon?x=1',
    'fragment' => 'https://shop.example.com/horizon#/dashboard',
    'both' => 'https://shop.example.com/horizon/?x=1#top',
    'empty query' => 'https://shop.example.com/horizon?',
]);

test('a poll of an absurd horizon stores a saturated reading', function () {
    $environment = Environment::factory()->create(['horizon_url' => 'https://shop.example.com/horizon']);

    fakeHorizonApi([
        'stats' => clientStats(['jobsPerMinute' => '1e20', 'failedJobs' => '3000000000', 'processes' => '-3']),
        'workload' => clientRaw('[{"name":"default","length":"99999999999999999999","wait":1e20,"processes":1}]'),
        'metrics/queues/default' => clientRaw('[{"runtime":1e999}]'),
    ]);

    $snapshot = app(PollEnvironment::class)->handle($environment);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->error)->toBeNull()
        ->and($snapshot->jobs_per_minute)->toBe(2147483647)
        ->and($snapshot->failed_in_window)->toBe(2147483647)
        ->and($snapshot->workers)->toBe(0)
        ->and($snapshot->pending)->toBe(2147483647)
        ->and($snapshot->max_wait_seconds)->toBe(2147483647);

    $state = EnvironmentState::query()->where('environment_id', $environment->id)->sole();

    expect($state->queues)->toBe([[
        'name' => 'default',
        'supervisor' => 'worker-1-a1b2:supervisor-1',
        'workers' => 1,
        'pending' => 2147483647,
        'waitSeconds' => 2147483647,
        'runtimeSeconds' => null,
    ]]);
});
