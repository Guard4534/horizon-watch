<?php

test('every operator setting of the panel reaches the production containers with its default', function () {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/horizon-watch.php');
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');

    preg_match_all("/env\\('(HORIZON_WATCH_[A-Z_]+)', ([^)]+)\\)/", $config, $settings, PREG_SET_ORDER);

    expect($settings)->not->toBeEmpty();

    foreach ($settings as [, $name, $default]) {
        expect($compose)->toContain("{$name}: \${{$name}:-".trim($default, "'").'}');
    }
});

test('production mail goes through smtp and never falls back to the log mailer', function () {
    $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.prod.yaml');
    $mail = require dirname(__DIR__, 2).'/config/mail.php';
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/config/mail.php');

    expect($compose)->toContain('MAIL_MAILER: ${MAIL_MAILER:-smtp}')
        ->toContain('MAIL_HOST: ${MAIL_HOST:-}')
        ->not->toMatch('/:-log\b/')
        ->and($source)->toContain("'default' => env('MAIL_MAILER', 'smtp')")
        ->and($mail['mailers']['failover']['mailers'])->not->toContain('log');
});
