<?php

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\AlertRuleMetric;
use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Externals\Http\Dns\Resolver;
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
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Horizon\FakeResolver;
use Tests\Support\AnotherAppKey;
use Tests\Support\FixtureServer;
use Tests\Support\TraceArguments;

const WEBHOOK_TEST_SECRET = 'whsec-correct-horse-battery-staple';

beforeAll(function () {
    $log = (string) tempnam(sys_get_temp_dir(), 'webhook-receiver-');

    $server = FixtureServer::start(dirname(__DIR__, 2).'/Fixtures/Webhook/receiver.php', ['RECEIVER_LOG_FILE' => $log], workers: 8);

    $GLOBALS['webhookReceiver'] = ['server' => $server, 'port' => $server->port, 'log' => $log];
});

afterAll(function () {
    ['server' => $server, 'log' => $log] = $GLOBALS['webhookReceiver'];

    $server->stop();
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
    config(['horizon-watch.version' => 'v1.0.0']);
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
        ->and($request['headers']['user-agent'])->toBe('HorizonWatch/v1.0.0')
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
    config(['horizon-watch.webhook_timeout_seconds' => 3]);
    $started = microtime(true);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe($reason)
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
})->with([
    '404' => ['not-found', DeliveryError::ClientError],
    '500' => ['server-error', DeliveryError::ServerError],
    '503 with an endless body' => ['endless-error', DeliveryError::ServerError],
]);

test('an error status keeps its code, and a 429 the wait it asks for', function (string $scenario, int $status, ?int $retryAfter) {
    $failure = webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']));

    expect($failure?->status)->toBe($status)
        ->and($failure?->retryAfter)->toBe($retryAfter);
})->with([
    'redirect' => ['redirect', 302, null],
    '404' => ['not-found', 404, null],
    '429' => ['too-many', 429, 30],
    '500' => ['server-error', 500, null],
]);

test('a Retry-After given as a date is read as the seconds left', function (string $header, ?int $seconds) {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $this->app->instance(Resolver::class, new FakeResolver(['hooks.example.com' => ['203.0.113.10']]));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => $header])]);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post('https://hooks.example.com/hook', WEBHOOK_TEST_SECRET, ['event' => 'test']))?->retryAfter)->toBe($seconds);
})->with([
    'a date' => ['Thu, 17 Sep 2026 12:00:45 GMT', 45],
    'a past date' => ['Thu, 17 Sep 2026 11:00:00 GMT', 0],
    'garbage' => ['soon', null],
]);

test('a receiver slower than the timeout fails as timeout', function () {
    $started = microtime(true);

    expect(webhookFailure(fn () => app(WebhookClient::class)->post(receiverUrl('slow'), WEBHOOK_TEST_SECRET, ['event' => 'test']))?->reason)->toBe(DeliveryError::Timeout)
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

test('an endless or oversized success body is cut at the cap without failing', function (string $scenario) {
    config(['horizon-watch.webhook_timeout_seconds' => 3]);
    $limit = ini_set('memory_limit', '256M');
    memory_reset_peak_usage();
    $memory = memory_get_usage(true);
    $started = microtime(true);

    app(WebhookClient::class)->post(receiverUrl($scenario), WEBHOOK_TEST_SECRET, ['event' => 'test']);

    expect(microtime(true) - $started)->toBeLessThan(3.0)
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

test('the request is pinned to the checked address, without redirects, proxy or decompression', function () {
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
        ->and($sent[0]['decode_content'])->toBeFalse()
        ->and($sent[0]['connect_timeout'])->toBe(5)
        ->and($sent[0]['timeout'])->toBe(5)
        ->and($resolver->asked)->toBe(['hooks.example.com']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://hooks.example.com/services/tok'
        && $request->header('Accept-Encoding') === ['identity']);
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
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'notifications', '--sleep' => 0]);
}

test('the webhook job stamps the send time and logs the host only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl('accepted'));

    $job = new SendAlertWebhook($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team));

    dispatch_sync($job);
    dispatch_sync($job);

    [$request] = receivedRequests();
    $body = json_decode($request['body'], true);
    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toHaveCount(1)
        ->and($log->delivery_id)->toBe($job->deliveryId)
        ->and($body)->toBe([
            'version' => 1,
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

test('an alert goes on the wire as the documented version 1 body', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:02:04', 'UTC'));
    URL::forceRootUrl('https://horizon-watch.example.com');
    URL::forceScheme('https');
    $team = webhookJobTeam(receiverUrl('accepted'));
    $environment = Environment::factory()->for(Application::factory()->for($team)->create(['name' => 'Città', 'slug' => 'citta']))->production()->create();
    $alert = Alert::factory()->for($environment)->critical()->create([
        'metric' => AlertRuleMetric::JobRuntime,
        'value' => 300,
        'detail' => ['job' => 'App\\Jobs\\ImportCatalog', 'queue' => 'imports', 'seconds' => 300],
        'opened_at' => CarbonImmutable::parse('2026-09-17 12:02:00', 'UTC'),
    ]);
    $job = new SendAlertWebhook($team->id, $alert->id, SentNotificationKind::CriticalAlert, WebhookPayload::forAlert($alert, WebhookPayload::OPENED));

    dispatch_sync($job);

    [$request] = receivedRequests();

    expect($request['body'])->toBe('{"version":1,"event":"alert.opened","delivery_id":"'.$job->deliveryId.'","alert":{"id":"'.$alert->id.'","rule":"job.runtime","severity":"critical","summary":"Città · production — A job has been running for 5 min","value":300,"threshold":120,"unit":"s","detail":{"value_is_lower_bound":false,"longest_job":"App\\\\Jobs\\\\ImportCatalog","longest_job_queue":"imports","queues_without_workers":[]},"application":"Città","environment":"production","opened_at":"2026-09-17T12:02:00Z","resolved_at":null,"url":"https://horizon-watch.example.com/'.$team->slug.'/environments/citta-production"},"organization":{"name":"Acme","slug":"'.$team->slug.'"},"sent_at":"2026-09-17T12:02:04Z"}')
        ->and($request['headers']['x-horizon-watch-signature'])->toBe(Signature::sign(WEBHOOK_TEST_SECRET, 1_789_646_524, $request['body']));
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

    SendAlertWebhook::dispatch($team->id, $alert->id, SentNotificationKind::CriticalAlert, WebhookPayload::forAlert($alert, 'alert.opened'))
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
        ->and($log->error)->toBe(DeliveryError::ServerError)
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

    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team))
        ->onConnection('database');

    runQueuedJob();

    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toBe([])
        ->and($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe(DeliveryError::Blocked)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('a webhook removed before the send is dropped silently', function () {
    $team = webhookJobTeam(receiverUrl('ok'));
    $job = new SendAlertWebhook($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team));
    $team->notificationSetting()->update(['webhook_url' => null, 'webhook_secret' => null]);

    dispatch_sync($job);

    expect(receivedRequests())->toBe([])
        ->and(DeliveryLog::query()->count())->toBe(0);
});

test('a receiver that refuses for good fails at once, logged with its status', function (string $scenario, DeliveryError $reason, int $status) {
    $team = webhookJobTeam(receiverUrl($scenario));

    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team))
        ->onConnection('database');

    runQueuedJob();

    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toHaveCount(1)
        ->and($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe($reason)
        ->and($log->http_status)->toBe($status)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'redirect' => ['redirect', DeliveryError::Redirect, 302],
    'not found' => ['not-found', DeliveryError::ClientError, 404],
    'gone' => ['gone', DeliveryError::ClientError, 410],
]);

test('a receiver that asks to come back is tried again after its Retry-After, capped, or the backoff', function (string $scenario, int $delay) {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl($scenario));

    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team))
        ->onConnection('database');

    runQueuedJob();

    $job = DB::table('jobs')->sole();

    expect(DeliveryLog::query()->count())->toBe(0)
        ->and($job->attempts)->toBe(1)
        ->and($job->available_at)->toBe(now()->addSeconds($delay)->getTimestamp());
})->with([
    '408' => ['request-timeout', 10],
    '429' => ['too-many', 30],
    '429 asking for an hour' => ['too-many-for-long', 300],
    '429 asking for no wait' => ['too-many-right-now', 10],
]);

test('a receiver still asking to come back on the last try is logged as failed with its status', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl('too-many'));

    SendAlertWebhook::dispatch($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team))
        ->onConnection('database');

    runQueuedJob();
    $this->travel(30)->seconds();
    runQueuedJob();
    $this->travel(60)->seconds();
    runQueuedJob();

    $log = DeliveryLog::query()->sole();

    expect(receivedRequests())->toHaveCount(3)
        ->and($log->status)->toBe(DeliveryStatus::Failed)
        ->and($log->error)->toBe(DeliveryError::ClientError)
        ->and($log->http_status)->toBe(429)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('for a day after a regeneration the replaced secret signs too', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $team = webhookJobTeam(receiverUrl('ok'));
    $team->notificationSetting()->firstOrFail()->update([
        'previous_webhook_secret' => 'whsec-replaced',
        'previous_webhook_secret_expires_at' => now()->addDay(),
    ]);
    $send = fn () => dispatch_sync(new SendAlertWebhook($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team)));

    $send();
    $this->travel(1)->day();
    $send();

    [$during, $after] = receivedRequests();
    $timestamp = (int) $during['headers']['x-horizon-watch-timestamp'];

    expect($during['headers']['x-horizon-watch-signature'])->toBe(Signature::sign(WEBHOOK_TEST_SECRET, $timestamp, $during['body']))
        ->and($during['headers']['x-horizon-watch-previous-signature'])->toBe(Signature::sign('whsec-replaced', $timestamp, $during['body']))
        ->and($after['headers'])->not->toHaveKey('x-horizon-watch-previous-signature')
        ->and($after['headers']['x-horizon-watch-signature'])->toBe(Signature::sign(WEBHOOK_TEST_SECRET, (int) $after['headers']['x-horizon-watch-timestamp'], $after['body']));
});

test('a replaced secret that no longer decrypts is left out and the delivery still goes', function () {
    $team = webhookJobTeam(receiverUrl('ok'));
    DB::table('notification_settings')->where('team_id', $team->id)->update([
        'previous_webhook_secret' => AnotherAppKey::encrypt('whsec-replaced'),
        'previous_webhook_secret_expires_at' => now()->addDay(),
    ]);

    dispatch_sync(new SendAlertWebhook($team->id, null, SentNotificationKind::Test, WebhookPayload::forTest($team)));

    [$request] = receivedRequests();

    expect($request['headers'])->toHaveKey('x-horizon-watch-signature')
        ->not->toHaveKey('x-horizon-watch-previous-signature')
        ->and(DeliveryLog::query()->sole()->status)->toBe(DeliveryStatus::Sent);
});
