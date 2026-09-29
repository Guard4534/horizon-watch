<?php

use App\Models\Environment;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
    (new DatabaseSeeder)->seedMockupOrganization($this->user->currentTeam);
    Readings::mockup($this->user->currentTeam);
});

test('a down environment carries its incident', function () {
    $failed = fn (int $index) => [
        'job' => "App\\Jobs\\Job{$index}",
        'queue' => 'default',
        'exception' => 'RuntimeException: failed',
        'tries' => 1,
        'failedAt' => now()->subMinutes($index)->toIso8601String(),
    ];
    $reserved = fn (int $index, int $secondsAgo) => [
        'job' => "App\\Jobs\\Long{$index}",
        'queue' => 'reports',
        'reservedAt' => now()->subSeconds($secondsAgo)->toIso8601String(),
    ];

    Environment::query()->where('slug', 'invoice-desk-production')->sole()->state->update([
        'failed_jobs' => array_map($failed, range(1, 5)),
        'pending_jobs' => [$reserved(1, 600), $reserved(2, 300), $reserved(3, 121), $reserved(4, 30)],
    ]);

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'invoice-desk-production']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.status', 'inactive')
            ->where('page.openAlert.metric', 'horizon.master_inactive')
            ->has('page.failedJobs', 5)
            ->has('page.longRunningJobs', 3)
            ->has('page.throughput', 48)
            ->has('page.maxWait', 48));
});

test('a healthy environment has no incident', function () {
    $this->actingAs($this->user);

    $healthy = collect(app(MonitoringRepository::class)->environments($this->user->currentTeam))
        ->first(fn ($environment) => $environment->status->isHealthy());

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => $healthy->id]))
        ->assertInertia(fn (Assert $page) => $page->where('page.openAlert', null));
});
