<?php

use App\Configuration\PendingImport;
use App\Enums\EnvironmentType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ConfigurationFiles;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    $this->team = Team::factory()->create(['name' => 'Example Organization', 'slug' => 'example-organization']);

    foreach (TeamRole::cases() as $role) {
        $this->{$role->value} = User::factory()->create();
        $this->team->members()->attach($this->{$role->value}, ['role' => $role->value]);
    }

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

    $this->route = fn (string $name): string => route($name, ['current_team' => $this->team->slug]);
    $this->file = fn (array $document, string $name = 'shop.json'): UploadedFile => UploadedFile::fake()
        ->createWithContent($name, json_encode($document, JSON_THROW_ON_ERROR));
    $this->upload = fn (array $document, string $name = 'shop.json') => $this->actingAs($this->admin)
        ->post(($this->route)('configuration.upload'), ['file' => ($this->file)($document, $name)]);
    $this->uploadId = fn (): ?string => app(PendingImport::class)->get($this->team, $this->admin)['id'] ?? null;
    $this->apply = fn (bool $update, ?string $uploadId = null) => $this->actingAs($this->admin)
        ->post(($this->route)('configuration.apply'), ['update' => $update, 'uploadId' => $uploadId ?? ($this->uploadId)()]);
});

test('an admin downloads the configuration of the organization as a dated file', function () {
    $response = $this->actingAs($this->admin)->get(($this->route)('configuration.download'));

    $response->assertOk()
        ->assertDownload('horizon-watch-example-organization-2026-10-16.json')
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

    $document = json_decode($response->getContent(), true);

    expect($document)->toMatchArray(['format' => 'horizon-watch.config', 'version' => 1, 'organization' => 'Example Organization'])
        ->and($document['applications'][0]['name'])->toBe('Acme Shop');
});

test('members and viewers can neither export nor import', function (string $role, string $method, string $name) {
    $this->actingAs($this->{$role})->{$method}(($this->route)($name))->assertForbidden();
})->with(['member', 'viewer'])->with([
    'export' => ['get', 'configuration.download'],
    'import page' => ['get', 'configuration.show'],
    'upload' => ['post', 'configuration.upload'],
    'discard' => ['delete', 'configuration.discard'],
    'apply' => ['post', 'configuration.apply'],
]);

test('the applications page offers export and import to those who may use them', function (string $role, bool $offered) {
    $this->actingAs($this->{$role})->get(($this->route)('applications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('page.canTransferConfiguration', $offered));
})->with([['owner', true], ['admin', true], ['member', false], ['viewer', false]]);

test('the applications page names the application names an export would repeat, to those who may export', function (string $role, array $names) {
    Application::factory()->for($this->team)->create(['name' => 'ACME SHOP']);

    $this->actingAs($this->{$role})->get(($this->route)('applications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('page.sharedApplicationNames', $names));
})->with([['admin', ['Acme Shop']], ['member', []]]);

test('an uploaded file shows its preview to the admin who sent it, until it is discarded', function () {
    ($this->upload)(ConfigurationFiles::document([['name' => 'Billing', 'environments' => [
        ConfigurationFiles::environment(['horizon_url' => 'https://billing.example.com/horizon', 'basic_auth_user' => 'ops']),
    ]]]), 'billing.json')->assertRedirect(($this->route)('configuration.show'));

    $this->actingAs($this->admin)->get(($this->route)('configuration.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/configuration/Import')
            ->where('page.uploadId', ($this->uploadId)())
            ->where('page.preview.fileName', 'billing.json')
            ->where('page.preview.organization', 'Example Organization')
            ->where('page.preview.blocked', false)
            ->has('page.preview.items', 2)
            ->where('page.preview.items.0.status', 'new')
            ->where('page.preview.items.1.type', 'production')
            ->where('page.preview.items.1.passwordNeeded', true));

    $this->actingAs($this->owner)->get(($this->route)('configuration.show'))
        ->assertInertia(fn (Assert $page) => $page->where('page.preview', null));

    $this->actingAs($this->admin)->delete(($this->route)('configuration.discard'), ['uploadId' => ($this->uploadId)()])
        ->assertRedirect(($this->route)('configuration.show'));

    $this->actingAs($this->admin)->get(($this->route)('configuration.show'))
        ->assertInertia(fn (Assert $page) => $page->where('page.preview', null));
});

test('a file the panel cannot read is refused with the reason', function (?UploadedFile $file, string $message) {
    $this->actingAs($this->admin)->post(($this->route)('configuration.upload'), ['file' => $file])
        ->assertSessionHasErrors(['file' => $message]);
})->with([
    'no file' => [fn () => null, 'Choose a configuration file.'],
    'over 1 MB' => [fn () => UploadedFile::fake()->create('big.json', 1025), 'This file is larger than 1 MB.'],
    'malformed json' => [fn () => UploadedFile::fake()->createWithContent('shop.json', '{"format":'), 'This file is not valid JSON.'],
    'another format' => [fn () => UploadedFile::fake()->createWithContent('shop.json', '{"format":"other","version":1,"applications":[]}'), 'This is not a Horizon Watch configuration file.'],
    'a newer version' => [fn () => UploadedFile::fake()->createWithContent('shop.json', json_encode(ConfigurationFiles::document(version: 2))), 'This file comes from a newer Horizon Watch: update the panel first.'],
]);

test('a disallowed address is marked invalid and the import refuses to run', function () {
    ($this->upload)(ConfigurationFiles::document([['name' => 'Billing', 'environments' => [
        ConfigurationFiles::environment(['horizon_url' => 'http://169.254.169.254/horizon']),
    ]]]));

    $this->actingAs($this->admin)->get(($this->route)('configuration.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.preview.blocked', true)
            ->where('page.preview.items.1.status', 'invalid')
            ->where('page.preview.items.1.errors', ['This address points to a network the panel does not call.']));

    ($this->apply)(false)
        ->assertSessionHasErrors(['file' => 'Fix the invalid items in the file and upload it again.']);

    expect(Application::where('name', 'Billing')->exists())->toBeFalse();
});

test('importing applies the file, says what it did and forgets the preview', function () {
    ($this->upload)(ConfigurationFiles::document([
        ['name' => 'Acme Shop', 'environments' => [ConfigurationFiles::environment(['poll_interval_seconds' => 120])]],
        ['name' => 'Billing', 'environments' => [ConfigurationFiles::environment(['horizon_url' => 'https://billing.example.com/horizon', 'basic_auth_user' => 'ops'])]],
    ]));

    ($this->apply)(false)
        ->assertRedirect(($this->route)('applications.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Configuration imported: 2 created, 0 updated, 2 left as they were. 1 environment waits for its basic-auth password.',
        ]);

    expect($this->production->fresh()->poll_interval_seconds)->toBe(60)
        ->and(Application::where('name', 'Billing')->sole()->environments()->sole()->polling_enabled)->toBeFalse();

    ($this->apply)(true)
        ->assertSessionHasErrors(['file' => 'Upload the file again: its preview has expired.']);
});

test('with the update switch the import updates what differs', function () {
    ($this->upload)(ConfigurationFiles::document([
        ['name' => 'Acme Shop', 'environments' => [ConfigurationFiles::environment(['poll_interval_seconds' => 120])]],
    ]));

    ($this->apply)(true)
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Configuration imported: 0 created, 1 updated, 1 left as they were.',
        ]);

    expect($this->production->fresh()->poll_interval_seconds)->toBe(120);
});

test('the import applies the file whose preview was confirmed, not one uploaded since', function () {
    ($this->upload)(ConfigurationFiles::document([['name' => 'Billing', 'environments' => []]]), 'billing.json');
    $first = ($this->uploadId)();
    ($this->upload)(ConfigurationFiles::document([['name' => 'Payroll', 'environments' => []]]), 'payroll.json');

    $this->actingAs($this->admin)->delete(($this->route)('configuration.discard'), ['uploadId' => $first])
        ->assertRedirect(($this->route)('configuration.show'));

    ($this->apply)(false, $first)
        ->assertSessionHasErrors(['file' => 'Another file was uploaded since: check its preview and import again.']);

    expect(Application::whereIn('name', ['Billing', 'Payroll'])->exists())->toBeFalse()
        ->and(app(PendingImport::class)->get($this->team, $this->admin)['fileName'] ?? null)->toBe('payroll.json');

    ($this->apply)(false)->assertRedirect(($this->route)('applications.index'));

    expect(Application::where('name', 'Payroll')->exists())->toBeTrue();
});
