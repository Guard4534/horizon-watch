<?php

use App\Models\User;
use App\Queries\WallQuery;
use Database\Seeders\DatabaseSeeder;

test('the wall lists the most severe environments first, then the busiest', function () {
    $user = User::factory()->create();
    (new DatabaseSeeder)->seedMockupOrganization($user->currentTeam);
    // ConfiguredMonitoringRepository resolves the viewer from the
    // authenticated guard, unlike phase 1's fake data: this Query can no
    // longer run without an authenticated member of the team.
    $this->actingAs($user);

    $environments = app(WallQuery::class)->handle($user->currentTeam)->environments;

    foreach (array_slice($environments, 1) as $index => $environment) {
        $previous = $environments[$index];

        expect([$previous->status->severity(), -$previous->pending] <=> [$environment->status->severity(), -$environment->pending])
            ->toBeLessThanOrEqual(0);
    }
});
