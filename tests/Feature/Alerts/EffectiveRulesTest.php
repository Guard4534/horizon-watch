<?php

use App\Alerts\EffectiveRule;
use App\Alerts\EffectiveRules;
use App\Alerts\RuleSet;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\RuleOrigin;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->application = Application::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->application)->create();
});

function effectiveOrganizationRule(Team $team, AlertRuleMetric $metric, array $fields): AlertRule
{
    return AlertRule::factory()->for($team)->create(['scope' => 'organization', 'metric' => $metric, ...$fields]);
}

function effectiveEnvironmentRule(Team $team, string $scope, AlertRuleMetric $metric, array $fields): AlertRule
{
    return AlertRule::factory()->for($team)->create(['scope' => $scope, 'metric' => $metric, ...$fields]);
}

test('without rows every metric uses the defaults of the code, from the organization', function () {
    $rules = app(EffectiveRules::class)->forEnvironment($this->environment);

    expect(array_keys($rules->rules))->toBe(array_map(fn (AlertRuleMetric $metric) => $metric->value, AlertRuleMetric::cases()));

    foreach (AlertRuleMetric::cases() as $metric) {
        expect($rules->for($metric))->toEqual(new EffectiveRule(
            metric: $metric,
            threshold: $metric->defaultThreshold(),
            severity: $metric->defaultSeverity(),
            notifyByEmail: $metric->notifiesByEmailByDefault(),
            enabled: true,
            thresholdOrigin: RuleOrigin::Organization,
            severityOrigin: RuleOrigin::Organization,
            notifyOrigin: RuleOrigin::Organization,
            enabledOrigin: RuleOrigin::Organization,
        ));
    }

    expect(RuleSet::defaults())->toEqual($rules);
});

test('the organization row replaces the defaults field by field', function () {
    effectiveOrganizationRule($this->team, AlertRuleMetric::QueuePending, [
        'threshold' => 5000,
        'severity' => null,
        'notify_email' => false,
        'enabled' => null,
    ]);

    $rule = app(EffectiveRules::class)->forEnvironment($this->environment)->for(AlertRuleMetric::QueuePending);

    expect($rule->threshold)->toBe(5000.0)
        ->and($rule->severity)->toBe(AlertSeverity::Warning)
        ->and($rule->notifyByEmail)->toBeFalse()
        ->and($rule->enabled)->toBeTrue()
        ->and($rule->thresholdOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->severityOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->notifyOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->enabledOrigin)->toBe(RuleOrigin::Organization);
});

test('an environment-name row replaces the defaults field by field and is marked as an override', function () {
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::QueuePending, [
        'threshold' => null,
        'severity' => AlertSeverity::Critical,
        'notify_email' => null,
        'enabled' => false,
    ]);

    $rule = app(EffectiveRules::class)->forEnvironment($this->environment)->for(AlertRuleMetric::QueuePending);

    expect($rule->threshold)->toBe(2000.0)
        ->and($rule->severity)->toBe(AlertSeverity::Critical)
        ->and($rule->notifyByEmail)->toBeTrue()
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->thresholdOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->severityOrigin)->toBe(RuleOrigin::Override)
        ->and($rule->notifyOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->enabledOrigin)->toBe(RuleOrigin::Override);
});

test('an environment-name row wins over the organization row and inherits from it where empty', function () {
    effectiveOrganizationRule($this->team, AlertRuleMetric::JobRuntime, [
        'threshold' => 300,
        'severity' => AlertSeverity::Critical,
        'notify_email' => true,
        'enabled' => false,
    ]);
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::JobRuntime, [
        'threshold' => 900,
        'severity' => null,
        'notify_email' => false,
        'enabled' => null,
    ]);

    $rule = app(EffectiveRules::class)->forEnvironment($this->environment)->for(AlertRuleMetric::JobRuntime);

    expect($rule->threshold)->toBe(900.0)
        ->and($rule->severity)->toBe(AlertSeverity::Critical)
        ->and($rule->notifyByEmail)->toBeFalse()
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->thresholdOrigin)->toBe(RuleOrigin::Override)
        ->and($rule->severityOrigin)->toBe(RuleOrigin::Organization)
        ->and($rule->notifyOrigin)->toBe(RuleOrigin::Override)
        ->and($rule->enabledOrigin)->toBe(RuleOrigin::Organization)
        ->and(app(EffectiveRules::class)->forEnvironment($this->environment)->for(AlertRuleMetric::QueuePending)->threshold)->toBe(2000.0);
});

test('an environment named with capitals uses the lowercase row', function () {
    $environment = Environment::factory()->for($this->application)->create(['name' => 'Worker-Batch']);
    effectiveEnvironmentRule($this->team, 'worker-batch', AlertRuleMetric::WorkersMissing, ['threshold' => 2]);

    expect(app(EffectiveRules::class)->forEnvironment($environment)->for(AlertRuleMetric::WorkersMissing)->threshold)->toBe(2.0)
        ->and(app(EffectiveRules::class)->forScope($this->team, 'worker-batch')->for(AlertRuleMetric::WorkersMissing)->threshold)->toBe(2.0);
});

test('rows of another environment name or another team do not apply', function () {
    $other = Team::factory()->create();
    effectiveOrganizationRule($other, AlertRuleMetric::QueuePending, ['threshold' => 10]);
    effectiveEnvironmentRule($other, 'production', AlertRuleMetric::QueuePending, ['threshold' => 20]);
    effectiveEnvironmentRule($this->team, 'staging', AlertRuleMetric::QueuePending, ['threshold' => 30]);

    $rules = app(EffectiveRules::class);

    expect($rules->forEnvironment($this->environment)->for(AlertRuleMetric::QueuePending)->threshold)->toBe(2000.0)
        ->and($rules->forScope($this->team, 'staging')->for(AlertRuleMetric::QueuePending)->threshold)->toBe(30.0)
        ->and($rules->forScope($other, 'organization')->for(AlertRuleMetric::QueuePending)->threshold)->toBe(10.0)
        ->and($rules->forScope($other, 'production')->for(AlertRuleMetric::QueuePending)->threshold)->toBe(20.0);
});

test('the organization scope ignores the environment rows', function () {
    effectiveOrganizationRule($this->team, AlertRuleMetric::QueueMaxWait, ['threshold' => 45]);
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::QueueMaxWait, ['threshold' => 30]);

    $rule = app(EffectiveRules::class)->forScope($this->team, 'organization')->for(AlertRuleMetric::QueueMaxWait);

    expect($rule->threshold)->toBe(45.0)
        ->and($rule->thresholdOrigin)->toBe(RuleOrigin::Organization);
});

test('one query serves every environment of a team for the whole request', function () {
    $preprod = Environment::factory()->for($this->application)->preprod()->create();
    $environment = Environment::query()->findOrFail($this->environment->id);
    $preprod = Environment::query()->findOrFail($preprod->id);

    DB::enableQueryLog();

    $rules = app(EffectiveRules::class);
    $rules->forEnvironment($environment);
    $rules->forEnvironment($preprod);
    app(EffectiveRules::class)->forScope($this->team, 'organization');
    app(EffectiveRules::class)->overrideCounts($this->team);

    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'alert_rules'));

    expect($queries)->toHaveCount(1)
        ->and(app(EffectiveRules::class))->toBe($rules);
});

test('the rules are read again for the next request or job', function () {
    $rules = app(EffectiveRules::class);
    $rules->forEnvironment($this->environment);

    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::QueuePending, ['threshold' => 10]);
    app()->forgetScopedInstances();

    expect(app(EffectiveRules::class))->not->toBe($rules)
        ->and(app(EffectiveRules::class)->forEnvironment($this->environment)->for(AlertRuleMetric::QueuePending)->threshold)->toBe(10.0);
});

test('the override count holds the rows with at least one field, per lowercase environment name', function () {
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::QueuePending, ['threshold' => 10]);
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::QueueMaxWait, ['threshold' => null, 'severity' => null, 'notify_email' => false, 'enabled' => null]);
    effectiveEnvironmentRule($this->team, 'production', AlertRuleMetric::JobRuntime, ['threshold' => null, 'severity' => null, 'notify_email' => null, 'enabled' => null]);
    effectiveEnvironmentRule($this->team, 'Worker-Batch', AlertRuleMetric::WorkersMissing, ['enabled' => false]);
    effectiveOrganizationRule($this->team, AlertRuleMetric::QueuePending, ['threshold' => 5]);
    effectiveEnvironmentRule(Team::factory()->create(), 'staging', AlertRuleMetric::QueuePending, ['threshold' => 5]);

    expect(app(EffectiveRules::class)->overrideCounts($this->team))->toBe([
        'production' => 2,
        'worker-batch' => 1,
    ]);
});
