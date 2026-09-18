<?php

use App\Actions\Alerts\UpdateAlertRules;
use App\Data\Alerts\AlertRulesInputData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->application = Application::factory()->for($this->team)->create();
    $this->production = Environment::factory()->for($this->application)->production()->create();
    Environment::factory()->for($this->application)->staging()->create();

    $this->memberOf = function (TeamRole $role, MemberVisibility $visibility = MemberVisibility::All): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->admin = ($this->memberOf)(TeamRole::Admin);

    $this->organizationRule = fn (array $overrides = []): array => array_merge([
        'metric' => AlertRuleMetric::QueuePending->value,
        'threshold' => 3000,
        'severity' => AlertSeverity::Critical->value,
        'notifyByEmail' => false,
        'enabled' => true,
    ], $overrides);

    $this->put = fn (string $scope, array $rules) => $this->put(
        route('alert-rules.update', ['current_team' => $this->team->slug, 'scope' => $scope]),
        ['rules' => $rules],
    );

    $this->rulesOf = function (string $scope): array {
        $rules = null;

        $this->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => $scope]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rules) {
                $rules = collect($page->toArray()['props']['page']['rules'])->keyBy('metric')->all();
            });

        return $rules;
    };
});

test('an admin saves the organization rules, and the page shows them as organization values', function () {
    $this->actingAs($this->admin);

    ($this->put)('organization', [($this->organizationRule)()])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Alert rules saved.');

    $row = AlertRule::query()->sole();
    expect($row->team_id)->toBe($this->team->id)
        ->and($row->scope)->toBe('organization')
        ->and($row->threshold)->toBe(3000.0)
        ->and($row->severity)->toBe(AlertSeverity::Critical)
        ->and($row->notify_email)->toBeFalse()
        ->and($row->enabled)->toBeTrue();

    $rule = ($this->rulesOf)('organization')['queue.pending'];
    expect($rule)->toMatchArray([
        'unit' => 'job',
        'severity' => 'critical',
        'notifyByEmail' => false,
        'enabled' => true,
        'overrideThreshold' => null,
        'overrideSeverity' => null,
        'overrideNotifyByEmail' => null,
        'overrideEnabled' => null,
        'origin' => 'organization',
    ])
        ->and((float) $rule['threshold'])->toBe(3000.0)
        ->and((float) $rule['minimum'])->toBe(1.0)
        ->and((float) $rule['maximum'])->toBe(2147483647.0);
});

test('saving the organization scope again updates the same row', function () {
    $this->actingAs($this->admin);

    ($this->put)('organization', [($this->organizationRule)()]);
    ($this->put)('organization', [($this->organizationRule)(['threshold' => 4000, 'enabled' => false])])
        ->assertSessionHasNoErrors();

    $row = AlertRule::query()->sole();
    expect($row->threshold)->toBe(4000.0)
        ->and($row->enabled)->toBeFalse();
});

test('an environment scope overrides field by field and inherits the rest', function () {
    $this->actingAs($this->admin);

    ($this->put)('organization', [($this->organizationRule)()]);
    ($this->put)('production', [[
        'metric' => 'queue.pending',
        'threshold' => 9000,
        'severity' => null,
        'notifyByEmail' => true,
        'enabled' => null,
    ]])->assertSessionHasNoErrors();

    $rule = ($this->rulesOf)('production')['queue.pending'];
    expect($rule)->toMatchArray([
        'severity' => 'critical',
        'notifyByEmail' => true,
        'enabled' => true,
        'overrideSeverity' => null,
        'overrideNotifyByEmail' => true,
        'overrideEnabled' => null,
        'origin' => 'override',
    ])
        ->and((float) $rule['threshold'])->toBe(9000.0)
        ->and((float) $rule['overrideThreshold'])->toBe(9000.0);

    $untouched = ($this->rulesOf)('production')['queue.max_wait'];
    expect($untouched['origin'])->toBe('organization')
        ->and($untouched['overrideThreshold'])->toBeNull();

    $scopes = null;
    $this->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertia(function (Assert $page) use (&$scopes) {
            $scopes = collect($page->toArray()['props']['page']['scopes'])->keyBy('id')->all();
        });
    expect($scopes['production']['overrideCount'])->toBe(1)
        ->and($scopes['staging']['overrideCount'])->toBe(0)
        ->and($scopes['organization']['overrideCount'])->toBe(0);
});

test('an environment rule with every field inherited is deleted', function () {
    $this->actingAs($this->admin);
    AlertRule::factory()->for($this->team)->forScope('production')->create(['metric' => AlertRuleMetric::QueuePending]);
    AlertRule::factory()->for($this->team)->forScope('production')->create(['metric' => AlertRuleMetric::QueueMaxWait, 'threshold' => 30]);

    ($this->put)('production', [[
        'metric' => 'queue.pending',
        'threshold' => null,
        'severity' => null,
        'notifyByEmail' => null,
        'enabled' => null,
    ]])->assertSessionHasNoErrors();

    expect(AlertRule::query()->pluck('metric')->all())->toBe([AlertRuleMetric::QueueMaxWait]);
});

test('an environment scope may leave every field inherited only when it is not the organization', function () {
    $this->actingAs($this->admin);
    $inherited = ['metric' => 'queue.pending', 'threshold' => null, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null];

    ($this->put)('organization', [$inherited])
        ->assertSessionHasErrors(['rules.0.threshold', 'rules.0.severity', 'rules.0.notifyByEmail', 'rules.0.enabled']);

    ($this->put)('staging', [$inherited])->assertSessionHasNoErrors();

    expect(AlertRule::query()->count())->toBe(0);
});

test('every field must be sent, even when inherited', function () {
    $this->actingAs($this->admin);

    ($this->put)('production', [['metric' => 'queue.pending', 'threshold' => 10]])
        ->assertSessionHasErrors(['rules.0.severity', 'rules.0.notifyByEmail', 'rules.0.enabled']);

    expect(AlertRule::query()->count())->toBe(0);
});

test('thresholds are bounded per metric', function (string $metric, mixed $threshold, bool $valid) {
    $this->actingAs($this->admin);

    $response = ($this->put)('organization', [($this->organizationRule)(['metric' => $metric, 'threshold' => $threshold])]);

    $valid
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors('rules.0.threshold');
})->with([
    'failed jobs at 49' => ['jobs.failed_per_hour', 49, true],
    'failed jobs at 50' => ['jobs.failed_per_hour', 50, false],
    'state rule at 0' => ['endpoint.unreachable', 0, false],
    'state rule at 1' => ['endpoint.unreachable', 1, true],
    'state rule at 1440' => ['horizon.paused', 1440, true],
    'state rule at 1441' => ['horizon.master_inactive', 1441, false],
    'pending at the integer limit' => ['queue.pending', 2147483647, true],
    'pending past the integer limit' => ['queue.pending', 2147483648, false],
    'wait at a day' => ['queue.max_wait', 86400, true],
    'runtime past a day' => ['job.runtime', 86401, false],
    'missing workers at 1000' => ['workers.missing', 1000, true],
    'missing workers at 1001' => ['workers.missing', 1001, false],
    'a fraction' => ['queue.pending', 10.5, false],
    'a negative number' => ['queue.pending', -5, false],
    'text' => ['queue.pending', 'many', false],
]);

test('the environment scope is bounded the same way', function () {
    $this->actingAs($this->admin);

    ($this->put)('production', [['metric' => 'jobs.failed_per_hour', 'threshold' => 50, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertSessionHasErrors('rules.0.threshold');
});

test('the payload is refused when it is malformed', function (array $payload, string $error) {
    $this->actingAs($this->admin);

    $this->put(route('alert-rules.update', ['current_team' => $this->team->slug, 'scope' => 'organization']), $payload)
        ->assertSessionHasErrors($error);

    expect(AlertRule::query()->count())->toBe(0);
})->with([
    'no rules' => [['rules' => []], 'rules'],
    'an unknown metric' => [['rules' => [['metric' => 'redis.memory', 'threshold' => 5, 'severity' => 'warning', 'notifyByEmail' => true, 'enabled' => true]]], 'rules.0.metric'],
    'an unknown severity' => [['rules' => [['metric' => 'queue.pending', 'threshold' => 5, 'severity' => 'fatal', 'notifyByEmail' => true, 'enabled' => true]]], 'rules.0.severity'],
    'the same metric twice' => [['rules' => [
        ['metric' => 'queue.pending', 'threshold' => 5, 'severity' => 'warning', 'notifyByEmail' => true, 'enabled' => true],
        ['metric' => 'queue.pending', 'threshold' => 6, 'severity' => 'warning', 'notifyByEmail' => true, 'enabled' => true],
    ]], 'rules.0.metric'],
]);

test('a scope that is not an environment name of the organization answers 404', function (string $scope) {
    $other = Team::factory()->create();
    Environment::factory()->for(Application::factory()->for($other))->create(['name' => 'elsewhere']);

    $this->actingAs($this->admin);

    ($this->put)($scope, [['metric' => 'queue.pending', 'threshold' => 5, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertNotFound();
    $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => $scope]))
        ->assertNotFound();

    expect(AlertRule::query()->count())->toBe(0);
})->with(['nope', 'elsewhere']);

test('a manager who does not see an environment cannot write its scope', function () {
    $admin = ($this->memberOf)(TeamRole::Admin, MemberVisibility::NonProduction);

    $this->actingAs($admin);

    ($this->put)('production', [['metric' => 'queue.pending', 'threshold' => 5, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertNotFound();
    ($this->put)('staging', [['metric' => 'queue.pending', 'threshold' => 5, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertSessionHasNoErrors();
});

test('reset on the organization removes only its rows, reset on a name only that name', function () {
    AlertRule::factory()->for($this->team)->create(['metric' => AlertRuleMetric::QueuePending]);
    AlertRule::factory()->for($this->team)->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    AlertRule::factory()->for($this->team)->forScope('production')->create();
    AlertRule::factory()->for($this->team)->forScope('staging')->create();
    $other = AlertRule::factory()->create();

    $this->actingAs($this->admin);

    $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => 'organization']))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Recommended rules restored.');

    expect(AlertRule::query()->orderBy('id')->pluck('scope')->all())->toBe(['production', 'staging', 'organization']);

    $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => 'production']))
        ->assertRedirect();

    expect(AlertRule::query()->orderBy('id')->pluck('scope')->all())->toBe(['staging', 'organization'])
        ->and($other->fresh())->not->toBeNull();

    $rule = ($this->rulesOf)('organization')['queue.pending'];
    expect((float) $rule['threshold'])->toBe(AlertRuleMetric::QueuePending->defaultThreshold());
});

test('only a role that manages alert rules may write them', function (TeamRole $role, bool $allowed) {
    $user = ($this->memberOf)($role);
    $this->actingAs($user);

    $update = ($this->put)('organization', [($this->organizationRule)()]);
    $reset = $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => 'production']));

    if ($allowed) {
        $update->assertRedirect();
        $reset->assertRedirect();
        expect(AlertRule::query()->count())->toBe(1);
    } else {
        $update->assertForbidden();
        $reset->assertForbidden();
        expect(AlertRule::query()->count())->toBe(0);
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('a non-manager is refused before the payload is validated', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Member));

    ($this->put)('organization', [['metric' => 'nope']])->assertForbidden();
});

test('someone outside the organization is refused', function () {
    $this->actingAs(User::factory()->create());

    ($this->put)('organization', [($this->organizationRule)()])->assertForbidden();

    expect(AlertRule::query()->count())->toBe(0);
});

test('the rules stay readable by every member, with the manage flag only for managers', function (TeamRole $role, bool $canManage) {
    $this->actingAs(($this->memberOf)($role))
        ->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => 'production']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.rules', 8)
            ->where('page.canManage', $canManage));
})->with([
    [TeamRole::Owner, true],
    [TeamRole::Admin, true],
    [TeamRole::Member, false],
    [TeamRole::Viewer, false],
]);

test('the environment page shows the rules of its name, and its thresholds follow them', function () {
    AlertRule::factory()->for($this->team)->create(['metric' => AlertRuleMetric::QueueMaxWait, 'threshold' => 45]);
    AlertRule::factory()->for($this->team)->forScope('production')->inheriting()->create(['metric' => AlertRuleMetric::QueuePending, 'threshold' => 7000]);

    $this->actingAs($this->admin)
        ->get(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $this->production->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.rules', 8)
            ->where('page.rules', fn ($rules) => collect($rules)->where('origin', 'override')->pluck('metric')->all() === ['queue.pending'])
            ->where('page.thresholds', fn ($thresholds) => (float) $thresholds['queue.pending'] === 7000.0
                && (float) $thresholds['queue.max_wait'] === 45.0
                && (float) $thresholds['job.runtime'] === AlertRuleMetric::JobRuntime->defaultThreshold()));
});

test('an unknown scope answers 404 before its payload is validated', function () {
    $this->actingAs($this->admin);

    ($this->put)('nope', [['metric' => 'nope', 'threshold' => -1]])->assertNotFound();
    ($this->put)('nope', [])->assertNotFound();
});

test('a manager who does not see an environment gets 404 for its scope whatever the payload', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Admin, MemberVisibility::NonProduction));

    ($this->put)('production', [['metric' => 'nope']])->assertNotFound();
});

test('the checks run in order: permission, then scope, then payload', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Member));
    ($this->put)('nope', [['metric' => 'nope']])->assertForbidden();

    $this->actingAs($this->admin);
    ($this->put)('nope', [['metric' => 'nope']])->assertNotFound();
    ($this->put)('organization', [['metric' => 'nope']])->assertInvalid(['rules.0.metric']);
});

test('the scope reaches the rules through the validation context, not through the current route', function (?string $scope, bool $valid) {
    $payload = [
        ...($scope === null ? [] : ['scope' => $scope]),
        'rules' => [['metric' => 'queue.pending', 'threshold' => null, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]],
    ];

    $attempt = fn () => AlertRulesInputData::validateAndCreate($payload);

    $valid ? expect($attempt())->toBeInstanceOf(AlertRulesInputData::class) : expect($attempt)->toThrow(ValidationException::class);
})->with([
    'an environment name inherits every field' => ['staging', true],
    'the organization needs every field' => ['organization', false],
    'the organization in any case' => ['Organization', false],
    'no scope at all is held to the organization' => [null, false],
]);

test('a scope sent in the body never replaces the scope of the route', function () {
    $this->actingAs($this->admin);

    $this->put(route('alert-rules.update', ['current_team' => $this->team->slug, 'scope' => 'organization']), [
        'scope' => 'staging',
        'rules' => [['metric' => 'queue.pending', 'threshold' => null, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]],
    ])->assertInvalid(['rules.0.threshold']);

    expect(AlertRule::query()->count())->toBe(0);
});

test('a scope written in capitals answers 404 like any unknown scope', function () {
    $this->actingAs($this->admin);

    ($this->put)('Organization', [['metric' => 'queue.pending', 'threshold' => null, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertNotFound();

    expect(AlertRule::query()->count())->toBe(0);
});

test('saving the same rules twice leaves one row per metric with the last values', function () {
    $this->actingAs($this->admin);

    ($this->put)('staging', [['metric' => 'queue.pending', 'threshold' => 50, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]])
        ->assertSessionHasNoErrors();
    ($this->put)('staging', [['metric' => 'queue.pending', 'threshold' => 70, 'severity' => 'critical', 'notifyByEmail' => null, 'enabled' => null]])
        ->assertSessionHasNoErrors();
    ($this->put)('organization', [($this->organizationRule)()])->assertSessionHasNoErrors();
    ($this->put)('organization', [($this->organizationRule)(['threshold' => 4000])])->assertSessionHasNoErrors();

    $rows = AlertRule::query()->orderBy('scope')->get();

    expect($rows->map(fn (AlertRule $row) => [$row->scope, $row->metric, $row->threshold, $row->severity])->all())->toBe([
        ['organization', AlertRuleMetric::QueuePending, 4000.0, AlertSeverity::Critical],
        ['staging', AlertRuleMetric::QueuePending, 70.0, AlertSeverity::Critical],
    ]);
});

test('a save that loses the race for the first row updates it instead of failing', function () {
    $competitor = null;
    $raced = false;

    DB::beforeExecuting(function (string $sql) use (&$competitor, &$raced) {
        if (! $raced && str_starts_with($sql, 'insert into "alert_rules"')) {
            $raced = true;
            $competitor = AlertRule::factory()->for($this->team)->forScope('staging')->create([
                'metric' => AlertRuleMetric::QueuePending,
                'threshold' => 10,
            ]);
        }
    });

    app(UpdateAlertRules::class)->handle($this->team, 'staging', AlertRulesInputData::from([
        'rules' => [['metric' => 'queue.pending', 'threshold' => 80, 'severity' => null, 'notifyByEmail' => null, 'enabled' => null]],
    ]));

    expect(AlertRule::query()->sole()->only(['id', 'threshold']))->toBe(['id' => $competitor->id, 'threshold' => 80.0]);
});

test('override rows of a name no environment carries any more stay listed, so they can be reset', function () {
    AlertRule::factory()->for($this->team)->forScope('legacy')->create(['metric' => AlertRuleMetric::QueuePending, 'threshold' => 10]);
    AlertRule::factory()->for($this->team)->forScope('legacy')->create(['metric' => AlertRuleMetric::QueueMaxWait, 'threshold' => 10]);

    $this->actingAs($this->admin);

    $this->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scopes', fn ($scopes) => collect($scopes)->last() == ['id' => 'legacy', 'color' => null, 'environmentCount' => 0, 'overrideCount' => 2]));

    $this->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => 'legacy']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.rules', fn ($rules) => (float) collect($rules)->firstWhere('metric', 'queue.pending')['overrideThreshold'] === 10.0));

    $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => 'legacy']))
        ->assertRedirect();

    expect(AlertRule::query()->count())->toBe(0);

    $this->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => 'legacy']))->assertNotFound();
});

test('override rows without environments stay hidden from a manager with narrowed visibility', function () {
    AlertRule::factory()->for($this->team)->forScope('legacy')->create(['metric' => AlertRuleMetric::QueuePending, 'threshold' => 10]);
    AlertRule::factory()->for($this->team)->forScope('production')->create(['metric' => AlertRuleMetric::QueuePending, 'threshold' => 10]);

    $this->actingAs(($this->memberOf)(TeamRole::Admin, MemberVisibility::NonProduction));

    $this->get(route('alert-rules.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scopes', fn ($scopes) => collect($scopes)->pluck('id')->all() === ['organization', 'staging']));

    $this->delete(route('alert-rules.reset', ['current_team' => $this->team->slug, 'scope' => 'legacy']))->assertNotFound();
});
