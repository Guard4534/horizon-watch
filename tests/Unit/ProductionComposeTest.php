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
