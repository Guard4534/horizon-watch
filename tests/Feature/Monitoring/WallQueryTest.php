<?php

use App\Models\Team;
use App\Queries\WallQuery;

test('the wall lists the most severe environments first, then the busiest', function () {
    $environments = app(WallQuery::class)->handle(Team::factory()->make())->environments;

    foreach (array_slice($environments, 1) as $index => $environment) {
        $previous = $environments[$index];

        expect([$previous->status->severity(), -$previous->pending] <=> [$environment->status->severity(), -$environment->pending])
            ->toBeLessThanOrEqual(0);
    }
});
