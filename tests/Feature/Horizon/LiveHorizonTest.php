<?php

use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonTarget;

test('a real horizon can be read', function () {
    $reading = app(HorizonReader::class)->read(new HorizonTarget(
        dashboardUrl: (string) env('HORIZON_WATCH_LIVE_URL'),
        username: env('HORIZON_WATCH_LIVE_USERNAME'),
        password: env('HORIZON_WATCH_LIVE_PASSWORD'),
    ), oldestPendingJobs: true);

    expect(in_array($reading->stats->status, ['running', 'paused', 'inactive'], true))->toBeTrue()
        ->and($reading->failedJobs)->not->toBeNull()
        ->and($reading->pendingJobs)->not->toBeNull();

    if ($reading->stats->status !== 'inactive') {
        expect($reading->masters)->not->toBeEmpty();
    }

    if ($reading->workload !== []) {
        expect($reading->runtimesFetched)->toBeTrue();
    }

    if (env('HORIZON_WATCH_LIVE_EXPECT_PAUSED')) {
        expect($reading->stats->status)->toBe('paused');
    }

    $expectedFailed = (int) env('HORIZON_WATCH_LIVE_EXPECT_FAILED', 0);

    if ($expectedFailed > 0) {
        expect(count($reading->failedJobs ?? []))->toBeGreaterThanOrEqual($expectedFailed);
    }

    if (env('HORIZON_WATCH_LIVE_EXPECT_RESERVED')) {
        expect(collect($reading->pendingJobs)->contains(
            fn (HorizonPendingJob $job) => $job->reservedAt !== null,
        ))->toBeTrue();
    }
})->skip(! env('HORIZON_WATCH_LIVE_URL'), 'HORIZON_WATCH_LIVE_URL is not set.');
