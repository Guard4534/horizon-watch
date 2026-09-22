<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:08:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->environment = Environment::factory()
        ->for(Application::factory()->for($this->user->currentTeam))
        ->create();
});

test('the environment page carries the grid its series is drawn on', function (string $range, string $startsAt, int $step, string $lastBucket) {
    EnvironmentSnapshot::factory()->for($this->environment)->create([
        'captured_at' => CarbonImmutable::parse($lastBucket),
        'jobs_per_minute' => 7,
        'max_wait_seconds' => 11,
    ]);

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->user->currentTeam->slug, 'environment' => $this->environment->slug, 'range' => $range]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.grid.startsAt', $startsAt)
            ->where('page.grid.stepSeconds', $step)
            ->where('page.throughput.47', 7)
            ->where('page.maxWait.47', 11)
            ->where('page.maxWait.46', 0));

    expect(CarbonImmutable::parse($startsAt)->addSeconds(47 * $step)->equalTo(CarbonImmutable::parse($lastBucket)))->toBeTrue();
})->with([
    '3h' => ['3h', '2026-09-17T07:11:15+00:00', 225, '2026-09-17T10:07:30+00:00'],
    '24h' => ['24h', '2026-09-16T10:30:00+00:00', 1800, '2026-09-17T10:00:00+00:00'],
    '7d' => ['7d', '2026-09-10T10:30:00+00:00', 12600, '2026-09-17T07:00:00+00:00'],
]);

test('the wall carries the three hour grid of its throughput', function () {
    EnvironmentSnapshot::factory()->for($this->environment)->create([
        'captured_at' => CarbonImmutable::parse('2026-09-17T07:11:15+00:00'),
        'jobs_per_minute' => 5,
    ]);

    $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => $this->user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.grid.startsAt', '2026-09-17T07:11:15+00:00')
            ->where('page.grid.stepSeconds', 225)
            ->where('page.throughput.0', 5)
            ->has('page.throughput', 48));
});
