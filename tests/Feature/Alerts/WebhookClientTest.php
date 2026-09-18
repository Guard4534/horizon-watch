<?php

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Externals\Horizon\Dns\Resolver;
use App\Externals\Webhook\Signature;
use App\Externals\Webhook\WebhookClient;
use App\Externals\Webhook\WebhookFailed;
use App\Jobs\SendAlertWebhook;
use App\Models\Alert;
use App\Models\AlertNotification as DeliveryLog;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Horizon\FakeResolver;
use Tests\Support\TraceArguments;

const WEBHOOK_TEST_SECRET = 'whsec-correct-horse-battery-staple';

beforeAll(function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $log = (string) tempnam(sys_get_temp_dir(), 'webhook-receiver-');

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__, 2).'/Fixtures/Webhook/receiver.php'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '8', 'RECEIVER_LOG_FILE' => $log],
    );

    $deadline = microtime(true) + 5;

    while (($probe = @fsockopen('127.0.0.1', $port, timeout: 0.1)) === false && microtime(true) < $deadline) {
        usleep(50_000);
    }

    if ($probe !== false) {
        fclose($probe);
    }

    $GLOBALS['webhookReceiver'] = ['process' => $process, 'port' => $port, 'log' => $log];
});

afterAll(function () {
    ['process' => $process, 'log' => $log] = $GLOBALS['webhookReceiver'];

    proc_terminate($process);
    proc_close($process);
    @unlink($log);
});

beforeEach(function () {
    config(['horizon-watch.webhook_timeout_seconds' => 1]);
    file_put_contents($GLOBALS['webhookReceiver']['log'], '');
});

function receiverUrl(string $scenario): string
{
    return 'http://127.0.0.1:'.$GLOBALS['webhookReceiver']['port'].'/'.$scenario.'/tok-path-secret';
}

/**
 * @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}>
 */
function receivedRequests(): array
{
    $lines = array_filter(explode("\n", (string) file_get_contents($GLOBALS['webhookReceiver']['log'])));

    return array_values(array_map(fn (string $line) => json_decode($line, true), $lines));
}

function webhookFailure(Closure $call): ?WebhookFailed
{
    try {
        $call();
    } catch (WebhookFailed $exception) {
        return $exception;
    }

    return null;
}

test('the signature is an hmac of the timestamp and the body', function () {
    $expected = 'sha256='.hash_hmac('sha256', '1789000000.{"event":"test"}', 'secret');

    expect(Signature::sign('secret', 1789000000, '{"event":"test"}'))->toBe($expected)
        ->and(Signature::sign('secret', 1789000001, '{"event":"test"}'))->not->toBe($expected)
        ->and(Signature::sign('other', 1789000000, '{"event":"test"}'))->not->toBe($expected)
        ->and(Signature::sign('secret', 1789000000, '{"event":"test" }'))->not->toBe($expected);
});

test('the payload is posted as json with a signature the receiver can verify', function () {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_020));

    app(WebhookClient::class)->post(receiverUrl('ok'), WEBHOOK_TEST_SECRET, [
        'event' => 'alert.opened',
        'alert' => ['url' => 'https://panel.example.com/acme/environments/production', 'application' => 'Città'],
    ]);

    $requests = receivedRequests();

    expect($requests)->toHaveCount(1);

    [$request] = $requests;

    expect($request['method'])->toBe('POST')
        ->and($request['path'])->toBe('/ok/tok-path-secret')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and($request['headers']['user-agent'])->toBe('HorizonWatch')
        ->and($request['headers']['x-horizon-watch-timestamp'])->toBe('1789000020')
        ->and($request['body'])->toBe('{"event":"alert.opened","alert":{"url":"https://panel.example.com/acme/environments/production","application":"Città"}}')
        ->and(Signature::sign(WEBHOOK_TEST_SECRET, 1_789_000_020, $request['body']))->toBe($request['headers']['x-horizon-watch-signature'])
        ->and($request['headers'])->not->toHaveKey('authorization');
});

test('any 2xx is a success and its body is ignored', function () {
    app(WebhookClient::class)->post(receiverUrl('accepted'), WEBHOOK_TEST_SECRET, ['event' => 'test']);

    expect(receivedRequests())->toHaveCount(1);
});

test('a redirect is not followed and fails as http_3xx', function () {
    $failure = webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl('redirect'), WEBHOOK_TEST_SECRET, ['event' => 'test']));

    expect($failure?->reason)->toBe(DeliveryError::Redirect)
        ->and(receivedRequests())->toHaveCount(1);
});

test('an error status fails with its class', function (string $scenario, DeliveryError $reason) {
    $started = microtime(true);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe($reason)
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
})->with([
    '404' => ['not-found', DeliveryError::ClientError],
    '500' => ['server-error', DeliveryError::ServerError],
    '503 with an endless body' => ['endless-error', DeliveryError::ServerError],
]);

test('a receiver slower than the timeout fails as timeout', function () {
    $started = microtime(true);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl('slow'), WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe(DeliveryError::Timeout)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

test('an endless or oversized success body is cut at the cap without failing', function (string $scenario) {
    $limit = ini_set('memory_limit', '256M');
    memory_reset_peak_usage();
    $memory = memory_get_usage(true);
    $started = microtime(true);

    app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']);

    expect(microtime(true) - $started)->toBeLessThan(1.0)
        ->and(memory_get_peak_usage(true) - $memory)->toBeLessThan(16 * 1024 * 1024);

    ini_set('memory_limit', (string) $limit);
})->with(['endless', 'announced-too-large']);

test('a closed port fails as unreachable', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post("http://127.0.0.1:{$port}/hook", WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)
        ->toBe(DeliveryError::Unreachable);
});

test('the network guard stops a blocked address before any request', function (string $url, DeliveryError $reason) {
    $this->app->instance(Resolver::class, new FakeResolver([
        'metadata.example.com' => ['203.0.113.11', '169.254.169.254'],
        '169.254.169.254' => ['169.254.169.254'],
    ]));
    Http::preventStrayRequests();
    Http::fake();

    expect(webhookFailure(fn () => app(WebhookClient::class)->post($url, WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe($reason);

    Http::assertNothingSent();
})->with([
    'link-local literal' => ['http://169.254.169.254/latest/meta-data', DeliveryError::Blocked],
    'name resolving to link-local' => ['https://metadata.example.com/hook', DeliveryError::Blocked],
    'metadata name' => ['http://metadata.google.internal/hook', DeliveryError::Blocked],
    'not http' => ['ftp://hooks.example.com/hook', DeliveryError::Blocked],
    'space in the url' => ['https://hooks.example.com/ho ok', DeliveryError::Blocked],
    'unresolvable name' => ['https://nowhere.example.com/hook', DeliveryError::Unreachable],
]);

test('private networks are refused when the operator blocks them', function () {
    config(['horizon-watch.block_private_networks' => true]);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl('ok'), WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe(DeliveryError::Blocked)
        ->and(receivedRequests())->toBe([]);
});

test('the request is pinned to the checked address, without redirects or proxy', function () {
    config(['horizon-watch.webhook_timeout_seconds' => 5]);
    $resolver = new FakeResolver(['hooks.example.com' => ['203.0.113.10']]);
    $this->app->instance(Resolver::class, $resolver);
    $sent = new ArrayObject;
    Http::preventStrayRequests();
    Http::fake(function (Request $request, array $options) use ($sent) {
        $sent[] = $options;

        return Http::response('', 204);
    });

    app(WebhookClient::class)->post('https://hooks.example.com/services/tok', WEBHOOK_TEST_SECRET, ['event' => 'test']);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:203.0.113.10'])
        ->and($sent[0]['allow_redirects'])->toBeFalse()
        ->and($sent[0]['proxy'])->toBe(['no' => ['*']])
        ->and($sent[0]['connect_timeout'])->toBe(5)
        ->and($sent[0]['timeout'])->toBe(5)
        ->and($resolver->asked)->toBe(['hooks.example.com']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://hooks.example.com/services/tok');
});

test('real curl reaches a name only through the address the guard pinned', function () {
    $port = $GLOBALS['webhookReceiver']['port'];
    $resolver = new FakeResolver(['receiver.internal' => ['127.0.0.1']]);
    $this->app->instance(Resolver::class, $resolver);

    app(WebhookClient::class)->post("http://receiver.internal:{$port}/ok/tok-path-secret", WEBHOOK_TEST_SECRET, ['event' => 'test']);

    [$request] = receivedRequests();

    expect($resolver->asked)->toBe(['receiver.internal'])
        ->and($request['headers']['host'])->toBe("receiver.internal:{$port}")
        ->and($request['path'])->toBe('/ok/tok-path-secret');

    $this->app->instance(Resolver::class, new FakeResolver(['receiver.internal' => ['127.0.0.2']]));

    expect(webhookFailure(fn () => app(WebhookClient::class)->post("http://receiver.internal:{$port}/ok/tok-path-secret", WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)
        ->toBe(DeliveryError::Unreachable)
        ->and(receivedRequests())->toHaveCount(1);
});

test('a failure never carries the url, the secret or the response body', function (string $scenario) {
    $failure = webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']));

    expect($failure)->not->toBeNull()
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->getMessage())->toBe($failure->reason->value);

    $text = $failure->getMessage().' '.$failure->getTraceAsString().' '.TraceArguments::ofAppFrames($failure);

    expect($text)->not->toContain('tok-path-secret')
        ->not->toContain('127.0.0.1')
        ->not->toContain(WEBHOOK_TEST_SECRET)
        ->not->toContain('response-body-secret-marker');
})->with(['not-found', 'server-error', 'redirect', 'slow']);

function webhookJobTeam(string $url): Team
{
    $team = Team::factory()->create(['name' => 'Acme']);
    NotificationSetting::factory()->for($team)->create(['webhook_url' => $url, 'webhook_secret' => WEBHOOK_TEST_SECRET]);

    return $team;
}

function runQueuedJob(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0]);
}

test('the webhook job stamps the send time and logs the host only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl('accepted'));

    $job = new SendAlertWebhook($team->id, null, SentNotificationKind::Test, 'test', WebhookPayload::forTest($team));

    dispatch_sync($job);
    dispatch_sync($job);

    [$request] = receivedRequests();
    $body = json_decode($request['body'], true);
    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toHaveCount(1)
        ->and($log->delivery_id)->toBe($job->deliveryId)
        ->and($body)->toBe([
            'event' => 'test',
            'delivery_id' => $job->deliveryId,
            'alert' => null,
            'organization' => ['name' => 'Acme', 'slug' => $team->slug],
            'sent_at' => '2026-09-17T12:00:00Z',
        ])
        ->and($log->channel)->toBe(NotificationChannel::Webhook)
        ->and($log->kind)->toBe(SentNotificationKind::Test)
        ->and($log->status)->toBe(DeliveryStatus::Sent)
        ->and($log->target)->toBe('127.0.0.1')
        ->and($log->error)->toBeNull()
        ->and(json_encode(DeliveryLog::query()->get()->toArray()))->not->toContain('tok-path-secret');
});

test('a failing receiver is tried three times with backoff, then logged once as failed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl('server-error'));
    $environment = Environment::factory()->for(Application::factory()->for($team))->create();
    $alert = Alert::factory()->for($environment)->critical()->create();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    SendAlertWebhook::dispatch($team->id, $alert->id, SentNotificationKind::CriticalAlert, 'alert.opened', WebhookPayload::forAlert($alert, 'alert.opened'))
        ->onConnection('database');

    runQueuedJob();
    runQueuedJob();

    expect(receivedRequests())->toHaveCount(1)
        ->and(DeliveryLog::query()->count())->toBe(0);

    $this->travel(10)->seconds();
    runQueuedJob();

    expect(receivedRequests())->toHaveCount(2)
        ->and(DeliveryLog::query()->count())->toBe(0);

    $this->travel(59)->seconds();
    runQueuedJob();

    expect(receivedRequests())->toHaveCount(2);

    $this->travel(1)->seconds();
    runQueuedJob();

    $bodies = array_map(fn (array $request) => json_decode($request['body'], true), receivedRequests());

    expect($bodies)->toHaveCount(3)
        ->and(array_unique(array_column($bodies, 'delivery_id')))->toHaveCount(1)
        ->and($bodies[0]['delivery_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(array_column($bodies, 'sent_at'))->toBe(['2026-09-17T12:00:00Z', '2026-09-17T12:00:10Z', '2026-09-17T12:01:10Z']);

    $log = DeliveryLog::query()->sole();

    expect($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe('http_5xx')
        ->and($log->target)->toBe('127.0.0.1')
        ->and($log->alert_id)->toBe($alert->id)
        ->and($log->delivery_id)->toBe($bodies[0]['delivery_id'])
        ->and(DB::table('jobs')->count())->toBe(0);

    $failedJobs = json_encode(DB::table('failed_jobs')->get());

    expect($failedJobs)->not->toContain('tok-path-secret')
        ->not->toContain(WEBHOOK_TEST_SECRET)
        ->not->toContain('response-body-secret-marker')
        ->and(implode("\n", $logged))->not->toContain('tok-path-secret')
        ->not->toContain(WEBHOOK_TEST_SECRET)
        ->not->toContain('response-body-secret-marker');
});

test('a blocked webhook fails at once without a request', function () {
    config(['horizon-watch.block_private_networks' => true]);
    $team = webhookJobTeam(receiverUrl('ok'));

    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, 'test', WebhookPayload::forTest($team))
        ->onConnection('database');

    runQueuedJob();

    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toBe([])
        ->and($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe('blocked')
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a webhook removed before the send is dropped silently', function () {
    $team = webhookJobTeam(receiverUrl('ok'));
    $job = new SendAlertWebhook($team->id, null, SentNotificationKind::Test, 'test', WebhookPayload::forTest($team));
    $team->notificationSetting()->update(['webhook_url' => null, 'webhook_secret' => null]);

    dispatch_sync($job);

    expect(receivedRequests())->toBe([])
        ->and(DeliveryLog::query()->count())->toBe(0);
});
