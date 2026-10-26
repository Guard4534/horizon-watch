<?php

use App\Configuration\ConfigurationExporter;
use App\Configuration\InputSource;
use App\Enums\EnvironmentType;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Tests\Support\ConfigurationFiles;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    $this->team = Team::factory()->create(['name' => 'Example Organization', 'slug' => 'example-organization']);
    $this->acme = Application::factory()->for($this->team)->create(['name' => 'Acme Shop']);
    $this->production = Environment::factory()->for($this->acme)->create([
        'name' => 'production',
        'type' => EnvironmentType::Production,
        'horizon_url' => 'https://shop.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
        'poll_interval_seconds' => 60,
        'polling_enabled' => true,
    ]);

    $this->file = function (array $document): string {
        $path = tempnam(sys_get_temp_dir(), 'horizon-watch-import-');
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        return $path;
    };
    $this->document = ConfigurationFiles::document(
        applications: [
            ['name' => 'Acme Shop', 'environments' => [ConfigurationFiles::environment(['poll_interval_seconds' => 120])]],
            ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment(['horizon_url' => 'https://billing.example.com/horizon'])]],
        ],
        alertRules: [['scope' => 'production', 'metric' => 'queue.max_wait', 'threshold' => 90]],
    );
});

test('the export command writes the file to standard output', function () {
    $this->artisan('applications:export', ['organization' => $this->team->slug])
        ->expectsOutputToContain('"format": "horizon-watch.config"')
        ->assertSuccessful();
});

test('the export command writes the file where --output says', function () {
    $path = sys_get_temp_dir().'/horizon-watch-export-'.uniqid().'.json';

    $this->artisan('applications:export', ['organization' => $this->team->slug, '--output' => $path])
        ->expectsOutput("Exported the configuration of Example Organization to {$path}.")
        ->assertSuccessful();

    $exporter = app(ConfigurationExporter::class);

    expect(file_get_contents($path))->toBe($exporter->toJson($exporter->export($this->team)));
});

test('the export command warns about application names the import would refuse', function () {
    Application::factory()->for($this->team)->create(['name' => 'acme shop']);

    $this->artisan('applications:export', ['organization' => $this->team->slug])
        ->expectsOutputToContain('More than one application is named Acme Shop: rename one of them, or importing this file is refused.')
        ->expectsOutputToContain('"format": "horizon-watch.config"')
        ->assertSuccessful();
});

test('both commands refuse an organization that does not exist', function (string $command, array $arguments) {
    $this->artisan($command, ['organization' => 'nowhere', ...$arguments])
        ->expectsOutput('No organization uses the slug nowhere.')
        ->assertFailed();
})->with([
    'export' => ['applications:export', []],
    'import' => ['applications:import', ['path' => '-']],
]);

test('a dry run prints the preview and changes nothing', function () {
    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => ($this->file)($this->document), '--dry-run' => true])
        ->expectsTable(['Item', 'Status', 'Details'], [
            ['Acme Shop', 'unchanged', ''],
            ['Acme Shop / production', 'changed, skipped (use --update)', 'differs: poll_interval_seconds'],
            ['Billing', 'new', ''],
            ['Billing / production', 'new', ''],
            ['rule production / queue.max_wait', 'new', ''],
        ])
        ->expectsOutput('Dry run: nothing was changed.')
        ->assertSuccessful();

    expect(Application::where('team_id', $this->team->id)->count())->toBe(1)
        ->and($this->production->fresh()->poll_interval_seconds)->toBe(60)
        ->and($this->team->alertRules()->count())->toBe(0);
});

test('the import command reads standard input and updates only with --update', function () {
    $this->app->instance(InputSource::class, new InputSource(stdin: ($this->file)($this->document)));

    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => '-'])
        ->expectsOutput('Configuration imported: 3 created, 0 updated, 2 left as they were.')
        ->assertSuccessful();

    expect($this->production->fresh()->poll_interval_seconds)->toBe(60);

    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => '-', '--update' => true])
        ->expectsOutput('Configuration imported: 0 created, 1 updated, 4 left as they were.')
        ->assertSuccessful();

    expect($this->production->fresh()->poll_interval_seconds)->toBe(120);
});

test('a file with an invalid item fails and changes nothing', function () {
    $path = ($this->file)(ConfigurationFiles::document([
        ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment(['name' => 'organization'])]],
    ]));

    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => $path, '--update' => true])
        ->expectsOutput('The file has invalid items: fix them and run the import again. Nothing was changed.')
        ->assertFailed();

    expect(Application::where('name', 'Billing')->exists())->toBeFalse();
});

test('a file the panel cannot read stops the import with the reason', function () {
    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => '/nonexistent/shop.json'])
        ->expectsOutput('Cannot read /nonexistent/shop.json.')
        ->assertFailed();

    $this->artisan('applications:import', ['organization' => $this->team->slug, 'path' => ($this->file)(ConfigurationFiles::document(version: 2))])
        ->expectsOutput('This file comes from a newer Horizon Watch: update the panel first.')
        ->assertFailed();
});
