<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->freezeTime();
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam;
    $this->application = Application::factory()->for($this->team)->create();
    $this->production = Environment::factory()->for($this->application)->production()->create();
    $this->staging = Environment::factory()->for($this->application)->staging()->create();
    $this->actingAs($this->user);

    $this->override = fn (string $scope, AlertRuleMetric $metric, array $values) => AlertRule::factory()->for($this->team)->create([
        'scope' => $scope,
        'metric' => $metric,
        'threshold' => null,
        'severity' => null,
        'notify_email' => null,
        'enabled' => null,
        ...$values,
    ]);

    $this->environmentPage = fn (Environment $environment) => $this->get(route('environments.show', [
        'current_team' => $this->team->slug,
        'environment' => $environment->slug,
    ]));
});

test('the queues of an environment are judged with the rules of its name', function () {
    ($this->override)('production', AlertRuleMetric::QueueMaxWait, ['threshold' => 90]);
    ($this->override)('staging', AlertRuleMetric::QueuePending, ['threshold' => 100]);

    $queues = [
        ['name' => 'slow', 'supervisor' => 'supervisor-1', 'workers' => 3, 'pending' => 10, 'waitSeconds' => 70, 'runtimeSeconds' => null],
        ['name' => 'busy', 'supervisor' => 'supervisor-1', 'workers' => 3, 'pending' => 150, 'waitSeconds' => 1, 'runtimeSeconds' => null],
    ];

    Readings::record($this->production, EnvironmentStatus::Degraded, state: ['queues' => $queues]);
    Readings::record($this->staging, EnvironmentStatus::Degraded, state: ['queues' => $queues]);

    ($this->environmentPage)($this->production)->assertInertia(fn (Assert $page) => $page
        ->where('page.queues.0.status', 'active')
        ->where('page.queues.1.status', 'active'));

    ($this->environmentPage)($this->staging)->assertInertia(fn (Assert $page) => $page
        ->where('page.queues.0.status', 'degraded')
        ->where('page.queues.1.status', 'degraded'));
});

test('a disabled rule does not degrade a queue', function () {
    ($this->override)('production', AlertRuleMetric::WorkersMissing, ['enabled' => false]);
    ($this->override)('organization', AlertRuleMetric::QueueMaxWait, ['enabled' => false]);

    Readings::record($this->production, EnvironmentStatus::Active, state: ['queues' => [
        ['name' => 'orphan', 'supervisor' => null, 'workers' => 0, 'pending' => 5, 'waitSeconds' => 500, 'runtimeSeconds' => null],
    ]]);
    Readings::record($this->staging, EnvironmentStatus::Active, state: ['queues' => [
        ['name' => 'orphan', 'supervisor' => null, 'workers' => 0, 'pending' => 5, 'waitSeconds' => 1, 'runtimeSeconds' => null],
    ]]);

    ($this->environmentPage)($this->production)->assertInertia(fn (Assert $page) => $page->where('page.queues.0.status', 'active'));
    ($this->environmentPage)($this->staging)->assertInertia(fn (Assert $page) => $page->where('page.queues.0.status', 'degraded'));
});

test('the long jobs of an environment are the ones over the runtime rule of its name', function () {
    ($this->override)('production', AlertRuleMetric::JobRuntime, ['threshold' => 900]);

    $jobs = [
        ['job' => 'App\\Jobs\\BuildReport', 'queue' => 'reports', 'reservedAt' => now()->subSeconds(600)->toIso8601String()],
        ['job' => 'App\\Jobs\\ImportCatalog', 'queue' => 'imports', 'reservedAt' => now()->subSeconds(1000)->toIso8601String()],
    ];

    Readings::record($this->production, state: ['pending_jobs' => $jobs]);
    Readings::record($this->staging, state: ['pending_jobs' => $jobs]);

    ($this->environmentPage)($this->production)->assertInertia(fn (Assert $page) => $page
        ->has('page.longRunningJobs', 1)
        ->where('page.longRunningJobs.0.job', 'App\\Jobs\\ImportCatalog'));

    ($this->environmentPage)($this->staging)->assertInertia(fn (Assert $page) => $page->has('page.longRunningJobs', 2));
});

test('every environment carries the thresholds of its own name', function () {
    ($this->override)('organization', AlertRuleMetric::QueuePending, ['threshold' => 500]);
    ($this->override)('Production', AlertRuleMetric::QueuePending, ['threshold' => 5000]);

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('page.failedPerHourThreshold')
            ->where('page.environments', fn ($environments) => collect($environments)->mapWithKeys(fn ($environment) => [
                $environment['id'] => (float) $environment['thresholds']['queue.pending'],
            ])->sortKeys()->all() === [$this->production->slug => 5000.0, $this->staging->slug => 500.0]
                && (float) collect($environments)->firstWhere('id', $this->staging->slug)['thresholds']['jobs.failed_per_hour']
                    === AlertRuleMetric::JobsFailedPerHour->defaultThreshold()));
});

test('the wall counts the environments over their own failure threshold', function () {
    ($this->override)('production', AlertRuleMetric::JobsFailedPerHour, ['threshold' => 30]);
    $preview = Environment::factory()->for($this->application)->create(['name' => 'preview']);
    ($this->override)('preview', AlertRuleMetric::JobsFailedPerHour, ['threshold' => 10]);

    Readings::record($this->production, snapshot: ['failed_last_hour' => 25]);
    Readings::record($this->staging, snapshot: ['failed_last_hour' => 25]);
    Readings::record($preview, snapshot: ['failed_last_hour' => 25]);

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.kpis.environmentsOverFailedRate', 2));
});

test('the application page gives each environment its own thresholds', function () {
    ($this->override)('production', AlertRuleMetric::QueueMaxWait, ['threshold' => 90]);

    $this->get(route('applications.show', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('page.thresholds')
            ->where('page.environments', fn ($environments) => collect($environments)->mapWithKeys(fn ($environment) => [
                $environment['id'] => (float) $environment['thresholds']['queue.max_wait'],
            ])->sortKeys()->all() === [$this->production->slug => 90.0, $this->staging->slug => 60.0]));
});

test('a disabled rule leaves its metric out of the thresholds of the environment', function () {
    ($this->override)('production', AlertRuleMetric::QueuePending, ['enabled' => false]);
    Readings::record($this->production);
    Readings::record($this->staging);

    ($this->environmentPage)($this->production)->assertInertia(fn (Assert $page) => $page
        ->where('page.environment.thresholds', fn ($thresholds) => ! $thresholds->has('queue.pending') && $thresholds->has('queue.max_wait')));

    ($this->environmentPage)($this->staging)->assertInertia(fn (Assert $page) => $page
        ->where('page.environment.thresholds', fn ($thresholds) => (float) $thresholds['queue.pending'] === AlertRuleMetric::QueuePending->defaultThreshold()));
});

test('the wall does not count an environment whose failure rule is disabled', function () {
    ($this->override)('production', AlertRuleMetric::JobsFailedPerHour, ['enabled' => false]);
    Readings::record($this->production, snapshot: ['failed_last_hour' => 25]);
    Readings::record($this->staging, snapshot: ['failed_last_hour' => 25]);

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.kpis.environmentsOverFailedRate', 1));
});

test('a disabled runtime rule lists no long job', function () {
    ($this->override)('production', AlertRuleMetric::JobRuntime, ['enabled' => false]);
    $jobs = [['job' => 'App\\Jobs\\ImportCatalog', 'queue' => 'imports', 'reservedAt' => now()->subHour()->toIso8601String()]];

    Readings::record($this->production, state: ['pending_jobs' => $jobs]);
    Readings::record($this->staging, state: ['pending_jobs' => $jobs]);

    ($this->environmentPage)($this->production)->assertInertia(fn (Assert $page) => $page->has('page.longRunningJobs', 0));
    ($this->environmentPage)($this->staging)->assertInertia(fn (Assert $page) => $page->has('page.longRunningJobs', 1));
});
