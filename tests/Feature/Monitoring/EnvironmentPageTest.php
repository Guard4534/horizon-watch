<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Environment;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
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
            ->has('series.throughput', 48)
            ->has('series.maxWait', 48));
});

test('a healthy environment has no incident', function () {
    $this->actingAs($this->user);

    $healthy = collect(app(MonitoringRepository::class)->environments($this->user->currentTeam))
        ->first(fn ($environment) => $environment->status->isHealthy());

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => $healthy->id]))
        ->assertInertia(fn (Assert $page) => $page->where('page.openAlert', null));
});

test('switching the range reloads only the series', function () {
    $this->actingAs($this->user)->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'invoice-desk-production']))->assertOk();

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'monitoring/environments/Show',
        'X-Inertia-Partial-Data' => 'series',
    ])->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'invoice-desk-production', 'range' => '24h']))
        ->assertOk()
        ->assertJsonPath('props.series.range', '24h')
        ->assertJsonCount(48, 'props.series.throughput')
        ->assertJsonMissingPath('props.page');
});

test('the series of an environment the viewer cannot see is not found', function () {
    $this->actingAs($this->user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'monitoring/environments/Show',
            'X-Inertia-Partial-Data' => 'series',
        ])
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'nowhere', 'range' => '24h']))
        ->assertNotFound();
});

test('the live poll of the page leaves the series alone', function () {
    $this->actingAs($this->user)->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'invoice-desk-production']))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'monitoring/environments/Show',
        'X-Inertia-Partial-Data' => 'page,openAlertCount,panelHealth',
    ])->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'invoice-desk-production', 'range' => '7d']))
        ->assertOk()
        ->assertJsonPath('props.page.environment.id', 'invoice-desk-production')
        ->assertJsonMissingPath('props.series');

    expect(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, '), s.captured_at')))->toBe([]);
});
