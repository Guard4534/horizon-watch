<?php

use App\Models\User;
use App\Queries\WallQuery;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Readings;

test('the wall lists the most severe environments first, then the busiest', function () {
    $user = User::factory()->create();
    (new DatabaseSeeder)->seedMockupOrganization($user->currentTeam);
    Readings::mockup($user->currentTeam);
    $this->actingAs($user);

    $environments = app(WallQuery::class)->handle($user->currentTeam)->environments;

    foreach (array_slice($environments, 1) as $index => $environment) {
        $previous = $environments[$index];

        expect([$previous->status->severity(), -$previous->pending] <=> [$environment->status->severity(), -$environment->pending])
            ->toBeLessThanOrEqual(0);
    }
});
