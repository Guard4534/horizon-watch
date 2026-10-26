<?php

use App\Configuration\ConfigurationExporter;
use App\Configuration\ConfigurationImporter;
use App\Configuration\ImportBlocked;
use App\Data\Configuration\ImportItemData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentType;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Tests\Support\ConfigurationFiles;

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Example Organization']);
    $this->acme = Application::factory()->for($this->team)->create(['name' => 'Acme Shop']);
    $this->production = Environment::factory()->for($this->acme)->create([
        'name' => 'production',
        'type' => EnvironmentType::Production,
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => 'ops',
        'basic_auth_password' => 'stored-secret',
        'poll_interval_seconds' => 60,
        'polling_enabled' => true,
    ]);
    $this->staging = Environment::factory()->for($this->acme)->create([
        'name' => 'staging',
        'type' => EnvironmentType::Staging,
        'horizon_url' => 'https://staging.shop.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
        'poll_interval_seconds' => 30,
        'polling_enabled' => true,
    ]);

    $this->importer = app(ConfigurationImporter::class);

    $this->productionEntry = fn (array $overrides = []): array => ConfigurationFiles::environment([
        'basic_auth_user' => 'ops',
        ...$overrides,
    ]);
    $this->stagingEntry = fn (array $overrides = []): array => ConfigurationFiles::environment([
        'name' => 'staging',
        'type' => 'staging',
        'horizon_url' => 'https://staging.shop.example.com/horizon',
        'poll_interval_seconds' => 30,
        ...$overrides,
    ]);
    $this->preview = fn (array $document) => $this->importer->preview($this->team, ConfigurationFiles::read($document), 'shop.json');
    $this->statuses = fn (array $document): array => collect(($this->preview)($document)->items)
        ->map(fn (ImportItemData $item) => [$item->kind->value, $item->label, $item->status->value, $item->changes])
        ->all();
    $this->apply = fn (array $document, bool $update) => $this->importer->apply($this->team, ConfigurationFiles::read($document), $update);
});

test('the preview marks every item new, changed, unchanged or invalid', function () {
    AlertRule::factory()->for($this->team)->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 5000,
        'severity' => AlertSeverity::Warning,
        'notify_email' => true,
        'enabled' => true,
        'for_minutes' => 0,
    ]);

    $document = ConfigurationFiles::document(
        applications: [
            ['name' => 'Acme Shop', 'environments' => [
                ($this->productionEntry)(['poll_interval_seconds' => 120]),
                ($this->stagingEntry)(),
                ConfigurationFiles::environment(['name' => 'demo', 'type' => 'demo', 'horizon_url' => 'https://demo.shop.example.com/horizon']),
            ]],
            ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment(['name' => 'Production'])]],
        ],
        alertRules: [
            ['scope' => 'organization', 'metric' => 'queue.pending', 'threshold' => 2000, 'severity' => 'warning', 'notify_by_email' => true, 'enabled' => true, 'for_minutes' => 0],
            ['scope' => 'demo', 'metric' => 'queue.max_wait', 'threshold' => 90],
        ],
    );

    expect(($this->statuses)($document))->toBe([
        ['application', 'Acme Shop', 'unchanged', []],
        ['environment', 'production', 'changed', ['poll_interval_seconds']],
        ['environment', 'staging', 'unchanged', []],
        ['environment', 'demo', 'new', []],
        ['application', 'Billing', 'new', []],
        ['environment', 'Production', 'invalid', []],
        ['alert_rule', 'organization / queue.pending', 'changed', ['threshold']],
        ['alert_rule', 'demo / queue.max_wait', 'new', []],
    ])->and(($this->preview)($document)->blocked)->toBeTrue();
});

test('an invalid environment says why, with the messages of the forms', function (array $overrides, string $message) {
    $preview = ($this->preview)(ConfigurationFiles::document([
        ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment($overrides)]],
    ]));

    expect($preview->blocked)->toBeTrue()
        ->and($preview->items[1]->status->value)->toBe('invalid')
        ->and($preview->items[1]->errors)->toContain($message);
})->with([
    'reserved name' => [['name' => 'organization'], 'This name is reserved for the organization-wide alert rules.'],
    'unknown type' => [['type' => 'worker'], 'The selected type is invalid.'],
    'disallowed address' => [['horizon_url' => 'http://169.254.169.254/horizon'], 'This address points to a network the panel does not call.'],
]);

test('alert rules go through the limits of the rule form and need a known scope', function (array $rule, string $message) {
    $preview = ($this->preview)(ConfigurationFiles::document([], [$rule]));

    expect($preview->items[0]->status->value)->toBe('invalid')
        ->and($preview->items[0]->errors)->toContain($message);
})->with([
    'threshold under the minimum' => [['scope' => 'organization', 'metric' => 'queue.pending', 'threshold' => 0, 'severity' => 'warning', 'notify_by_email' => true, 'enabled' => true, 'for_minutes' => 0], 'The threshold field must be at least 1.'],
    'organization row missing a field' => [['scope' => 'organization', 'metric' => 'queue.pending', 'threshold' => 10], 'The severity field is required.'],
    'unknown metric' => [['scope' => 'organization', 'metric' => 'queue.length', 'threshold' => 10, 'severity' => 'warning', 'notify_by_email' => true, 'enabled' => true], 'The selected metric is invalid.'],
    'unknown scope' => [['scope' => 'nowhere', 'metric' => 'queue.pending', 'threshold' => 10], 'No environment is named nowhere, in this organization or in the file.'],
    'override that sets nothing' => [['scope' => 'staging', 'metric' => 'queue.pending'], 'This rule sets nothing: give it at least one field.'],
    'duration on a state rule' => [['scope' => 'staging', 'metric' => 'endpoint.unreachable', 'for_minutes' => 5], 'The duration field is prohibited.'],
]);

test('the same item twice in the file, or two applications of the organization with one name, are invalid', function () {
    Application::factory()->for($this->team)->create(['name' => 'Billing']);
    Application::factory()->for($this->team)->create(['name' => 'billing']);

    $preview = ($this->preview)(ConfigurationFiles::document(
        applications: [
            ['name' => 'Payroll', 'environments' => [
                ConfigurationFiles::environment(['name' => 'demo', 'type' => 'demo']),
                ConfigurationFiles::environment(['name' => 'demo', 'type' => 'demo']),
            ]],
            ['name' => 'payroll', 'environments' => []],
            ['name' => 'Billing', 'environments' => []],
        ],
        alertRules: [
            ['scope' => 'staging', 'metric' => 'queue.pending', 'threshold' => 10],
            ['scope' => 'staging', 'metric' => 'queue.pending', 'threshold' => 20],
        ],
    ));

    expect(collect($preview->items)->map(fn (ImportItemData $item) => [$item->label, $item->errors])->all())->toBe([
        ['Payroll', []],
        ['demo', []],
        ['demo', ['This file lists the environment demo twice in this application.']],
        ['payroll', ['This file lists the application payroll twice.']],
        ['Billing', ['Two applications of this organization are named Billing: rename one of them first.']],
        ['staging / queue.pending', []],
        ['staging / queue.pending', ['This file lists this rule twice for staging.']],
    ]);
});

test('an application cannot bring more environments than the wizard allows', function () {
    config(['horizon-watch.limits.wizard_environments' => 2]);

    $preview = ($this->preview)(ConfigurationFiles::document([
        ['name' => 'Payroll', 'environments' => [
            ConfigurationFiles::environment(['name' => 'one']),
            ConfigurationFiles::environment(['name' => 'two']),
            ConfigurationFiles::environment(['name' => 'three']),
        ]],
    ]));

    expect($preview->items[0]->errors)->toBe(['An application can bring at most 2 environments in one import.']);
});

test('without the update switch the import creates what is new and leaves the rest as it is', function () {
    $summary = ($this->apply)(ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => [
        ($this->productionEntry)(['poll_interval_seconds' => 120]),
        ConfigurationFiles::environment(['name' => 'demo', 'type' => 'demo', 'horizon_url' => 'https://demo.shop.example.com/horizon']),
    ]]]), false);

    expect([$summary->created, $summary->updated, $summary->skipped])->toBe([1, 0, 2])
        ->and($this->production->fresh()->poll_interval_seconds)->toBe(60)
        ->and($this->acme->environments()->where('name', 'demo')->sole()->type)->toBe(EnvironmentType::Demo);
});

test('with the update switch the items that differ take the values of the file, and the same user keeps its password', function () {
    $summary = ($this->apply)(ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => [
        ($this->productionEntry)(['poll_interval_seconds' => 120, 'horizon_url' => 'https://shop.example.com/ops/horizon']),
    ]]]), true);

    $production = $this->production->fresh();

    expect([$summary->created, $summary->updated, $summary->skipped, $summary->passwordNeeded])->toBe([0, 1, 1, 0])
        ->and($production->poll_interval_seconds)->toBe(120)
        ->and($production->horizon_url)->toBe('https://shop.example.com/ops/horizon')
        ->and($production->basic_auth_password)->toBe('stored-secret')
        ->and($production->polling_enabled)->toBeTrue();
});

test('a new environment with a basic-auth user arrives with no password and collection paused', function () {
    $document = ConfigurationFiles::document([['name' => 'Billing', 'environments' => [
        ConfigurationFiles::environment(['horizon_url' => 'https://billing.example.com/horizon', 'basic_auth_user' => 'ops']),
    ]]]);

    expect(($this->preview)($document)->items[1]->passwordNeeded)->toBeTrue();

    $summary = ($this->apply)($document, false);
    $environment = Application::where('name', 'Billing')->sole()->environments()->sole();

    expect($environment->basic_auth_user)->toBe('ops')
        ->and($environment->getRawOriginal('basic_auth_password'))->toBeNull()
        ->and($environment->polling_enabled)->toBeFalse()
        ->and($environment->waitsForPassword())->toBeTrue()
        ->and($summary->passwordNeeded)->toBe(1);
});

test('an environment whose basic-auth user changes loses its stored password and waits for a new one', function () {
    $document = ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => [
        ($this->productionEntry)(['basic_auth_user' => 'monitor']),
    ]]]);

    $item = ($this->preview)($document)->items[1];

    expect($item->changes)->toBe(['basic_auth_user', 'polling_enabled'])
        ->and($item->passwordNeeded)->toBeTrue();

    ($this->apply)($document, true);
    $production = $this->production->fresh();

    expect($production->basic_auth_user)->toBe('monitor')
        ->and($production->getRawOriginal('basic_auth_password'))->toBeNull()
        ->and($production->polling_enabled)->toBeFalse();
});

test('a stored password never follows an environment to another host', function () {
    ($this->apply)(ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => [
        ($this->productionEntry)(['horizon_url' => 'https://shop.example.net/horizon']),
    ]]]), true);

    $production = $this->production->fresh();

    expect($production->horizon_url)->toBe('https://shop.example.net/horizon')
        ->and($production->getRawOriginal('basic_auth_password'))->toBeNull()
        ->and($production->polling_enabled)->toBeFalse();
});

test('applications match by name whatever the case, and the file decides the spelling', function () {
    $document = ConfigurationFiles::document([['name' => 'ACME SHOP', 'environments' => [($this->stagingEntry)()]]]);

    expect(($this->statuses)($document))->toBe([
        ['application', 'ACME SHOP', 'changed', ['name']],
        ['environment', 'staging', 'unchanged', []],
    ]);

    ($this->apply)($document, true);

    expect($this->acme->fresh()->name)->toBe('ACME SHOP')
        ->and($this->acme->fresh()->slug)->toBe('acme-shop')
        ->and(Application::where('team_id', $this->team->id)->count())->toBe(1);
});

test('an untidy address is stored tidy, and the same address in the file again is not a change', function () {
    $document = ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => [
        ($this->stagingEntry)(['horizon_url' => 'HTTPS://Staging.Shop.Example.COM/horizon/api/masters/']),
        ConfigurationFiles::environment(['name' => 'demo', 'type' => 'demo', 'horizon_url' => 'HTTPS://Demo.Shop.Example.COM/horizon/api/']),
    ]]]);

    expect(($this->statuses)($document))->toBe([
        ['application', 'Acme Shop', 'unchanged', []],
        ['environment', 'staging', 'unchanged', []],
        ['environment', 'demo', 'new', []],
    ]);

    ($this->apply)($document, true);

    expect($this->acme->environments()->where('name', 'demo')->sole()->horizon_url)->toBe('https://demo.shop.example.com/horizon')
        ->and($this->staging->fresh()->horizon_url)->toBe('https://staging.shop.example.com/horizon')
        ->and(($this->statuses)($document))->toBe([
            ['application', 'Acme Shop', 'unchanged', []],
            ['environment', 'staging', 'unchanged', []],
            ['environment', 'demo', 'unchanged', []],
        ]);

    $summary = ($this->apply)($document, true);

    expect([$summary->created, $summary->updated, $summary->skipped])->toBe([0, 0, 3]);
});

test('override rows take the values of the file and the organization rows are written whole', function () {
    AlertRule::factory()->for($this->team)->create([
        'scope' => 'staging',
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 50,
        'severity' => AlertSeverity::Critical,
        'notify_email' => null,
        'enabled' => null,
        'for_minutes' => null,
    ]);

    ($this->apply)(ConfigurationFiles::document([], [
        ['scope' => 'staging', 'metric' => 'queue.pending', 'threshold' => 80],
        ['scope' => 'organization', 'metric' => 'endpoint.unreachable', 'threshold' => 3, 'severity' => 'critical', 'notify_by_email' => false, 'enabled' => true],
    ]), true);

    $override = AlertRule::where('team_id', $this->team->id)->where('scope', 'staging')->sole();
    $organization = AlertRule::where('team_id', $this->team->id)->where('scope', 'organization')->sole();

    expect([$override->threshold, $override->severity])->toBe([80.0, null])
        ->and([$organization->metric, $organization->threshold, $organization->notify_email, $organization->for_minutes])
        ->toBe([AlertRuleMetric::EndpointUnreachable, 3.0, false, null]);
});

test('one invalid item blocks the whole import and nothing is written', function () {
    $document = ConfigurationFiles::document([
        ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment(['horizon_url' => 'https://billing.example.com/horizon'])]],
        ['name' => 'Payroll', 'environments' => [ConfigurationFiles::environment(['type' => 'worker'])]],
    ]);

    expect(fn () => ($this->apply)($document, true))
        ->toThrow(ImportBlocked::class, 'Fix the invalid items in the file and upload it again.');

    expect(Application::where('team_id', $this->team->id)->pluck('name')->all())->toBe(['Acme Shop']);
});

test('items missing from the file stay as they are', function () {
    AlertRule::factory()->for($this->team)->inheriting()->create(['scope' => 'staging', 'metric' => AlertRuleMetric::QueuePending, 'threshold' => 50]);

    ($this->apply)(ConfigurationFiles::document([['name' => 'Billing', 'environments' => []]]), true);

    expect($this->acme->environments()->count())->toBe(2)
        ->and(AlertRule::where('team_id', $this->team->id)->count())->toBe(1)
        ->and(Application::where('team_id', $this->team->id)->orderBy('id')->pluck('name')->all())->toBe(['Acme Shop', 'Billing']);
});

test('export, import into an empty organization and export again give the same file', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    AlertRule::factory()->for($this->team)->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 2000,
        'severity' => AlertSeverity::Warning,
        'notify_email' => true,
        'enabled' => true,
        'for_minutes' => 5,
    ]);
    AlertRule::factory()->for($this->team)->inheriting()->create(['scope' => 'production', 'metric' => AlertRuleMetric::QueueMaxWait, 'threshold' => 90]);

    $exporter = app(ConfigurationExporter::class);
    $first = $exporter->export($this->team);
    $target = Team::factory()->create(['name' => 'Example Organization']);

    $summary = $this->importer->apply($target, ConfigurationFiles::read($first), false);

    $expected = $first;
    $expected['applications'][0]['environments'][0]['polling_enabled'] = false;

    expect($summary->created)->toBe(5)
        ->and($summary->passwordNeeded)->toBe(1)
        ->and($exporter->export($target))->toBe($expected);
});

test('an organization rule with empty fields is unchanged against its own export', function () {
    AlertRule::factory()->for($this->team)->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 2000,
        'severity' => null,
        'notify_email' => null,
        'enabled' => true,
        'for_minutes' => null,
    ]);

    $exported = app(ConfigurationExporter::class)->export($this->team);

    expect(collect(($this->statuses)($exported))->firstWhere(1, 'organization / queue.pending'))
        ->toBe(['alert_rule', 'organization / queue.pending', 'unchanged', []]);
});
