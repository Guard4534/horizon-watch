<?php

use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;

test('a real horizon can be read', function () {
    $reading = app(HorizonReader::class)->read(new HorizonTarget(
        dashboardUrl: (string) env('HORIZON_WATCH_LIVE_URL'),
        username: env('HORIZON_WATCH_LIVE_USERNAME'),
        password: env('HORIZON_WATCH_LIVE_PASSWORD'),
    ));

    expect(in_array($reading->stats->status, ['running', 'paused', 'inactive'], true))->toBeTrue();
})->skip(! env('HORIZON_WATCH_LIVE_URL'), 'HORIZON_WATCH_LIVE_URL is not set.');
