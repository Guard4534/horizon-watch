<?php

use Illuminate\Support\Str;

test('every operator setting of the panel reaches the production containers with its default', function () {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/horizon-watch.php');
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');

    preg_match_all("/env\\('(HORIZON_WATCH_[A-Z_]+)', ([^)]+)\\)/", $config, $settings, PREG_SET_ORDER);

    expect($settings)->not->toBeEmpty();

    foreach ($settings as [, $name, $default]) {
        expect($compose)->toContain("{$name}: \${{$name}:-".trim($default, "'").'}');
    }
});

test('production mail goes through smtp and the operator cannot pick another mailer', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $mail = require dirname(__DIR__, 2).'/config/mail.php';
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/config/mail.php');

    expect($compose)->toContain("\n        MAIL_MAILER: smtp\n")
        ->not->toContain('${MAIL_MAILER')
        ->toContain('MAIL_HOST: ${MAIL_HOST:-}')
        ->not->toMatch('/:-log\b/')
        ->and($source)->toContain("'default' => env('MAIL_MAILER', 'smtp')")
        ->and($mail['mailers']['failover']['mailers'])->not->toContain('log');
});

test('readings and notifications are served by separate workers, and an older compose file still serves every queue', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/docker/prod/entrypoint.sh');
    $development = (string) file_get_contents(dirname(__DIR__, 2).'/compose.yaml');
    $build = (string) file_get_contents(dirname(__DIR__, 2).'/compose.build.yaml');

    expect($compose)->toMatch("/\n    worker:\n        <<: \*app\n        command: \['worker', 'polls,default'\]\n/")
        ->toMatch("/\n    notifier:\n        <<: \*app\n        command: \['worker', 'notifications,default'\]\n/")
        ->and($entrypoint)->toContain('queue:work --queue="${2:-notifications,polls,default}"')
        ->and($development)->toContain('artisan queue:listen --queue=notifications,polls,default')
        ->and($build)->toContain("\n    notifier:\n        <<: *build\n");
});

test('containers stop only after the longest job could finish, under an init process', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $config = require dirname(__DIR__, 2).'/config/horizon-watch.php';
    $supervisor = (string) file_get_contents(dirname(__DIR__, 2).'/docker/prod/supervisord.conf');
    $anchor = Str::before($compose, "\nservices:");

    preg_match('/\n    stop_grace_period: (\d+)s\n/', $anchor, $grace);

    expect($anchor)->toContain("\n    init: true\n")
        ->and((int) ($grace[1] ?? 0))->toBeGreaterThan(max($config['readings']['poll_job_timeout_seconds'], $config['notifications']['delivery_timeout_seconds']))
        ->and(substr_count($compose, '<<: *app'))->toBe(4)
        ->and(substr_count($supervisor, 'stopsignal=QUIT'))->toBe(2);
});

test('an smtp server that does not answer gives up before the delivery job times out', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $mail = require dirname(__DIR__, 2).'/config/mail.php';
    $config = require dirname(__DIR__, 2).'/config/horizon-watch.php';

    expect($mail['mailers']['smtp']['timeout'])->toBe(10)
        ->toBeLessThan($config['notifications']['delivery_timeout_seconds'])
        ->and($compose)->toContain('MAIL_TIMEOUT: ${MAIL_TIMEOUT:-10}');
});

test('the scheduler and the workers are healthy only while they keep working, and the web container stops when it cannot serve', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/docker/prod/entrypoint.sh');
    $supervisor = (string) file_get_contents(dirname(__DIR__, 2).'/docker/prod/supervisord.conf');
    $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

    expect($compose)->not->toContain('pgrep')
        ->toContain("test: ['CMD', 'curl', '-fsS', 'http://127.0.0.1:8080/up']")
        ->and(substr_count($compose, "test: ['CMD', 'horizon-watch', 'health', '--role=scheduler']"))->toBe(1)
        ->and(substr_count($compose, "test: ['CMD', 'horizon-watch', 'health', '--role=worker']"))->toBe(2)
        ->and($entrypoint)->toContain('php artisan horizon-watch:health "$@"')
        ->and($supervisor)->toContain("[eventlistener:exit-on-fatal]\ncommand=/usr/local/bin/supervisor-fatal\nevents=PROCESS_STATE_FATAL\n")
        ->and($dockerfile)->toContain('COPY --chmod=755 docker/prod/supervisor-fatal.sh /usr/local/bin/supervisor-fatal')
        ->toContain('ENV HORIZON_WATCH_VERSION=${VERSION}');
});
