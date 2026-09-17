<?php

use App\Actions\Monitoring\TestConnection;
use App\Data\Applications\TestConnectionData;
use App\Enums\HorizonStatus;
use App\Enums\MemberVisibility;
use App\Enums\ReadingError;
use App\Enums\TeamRole;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonProbe;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonReading;
use App\Externals\Horizon\HorizonTarget;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->reader = new class implements HorizonReader
    {
        /** @var list<array{url: string, username: string|null, password: string|null}> */
        public array $probed = [];

        public HorizonProbe|Throwable $outcome;

        public function __construct()
        {
            $this->outcome = new HorizonProbe(status: 'running', masterCount: 2, latencyMs: 84);
        }

        public function read(HorizonTarget $target): HorizonReading
        {
            throw new LogicException('A connection test must never take a reading.');
        }

        public function probe(HorizonTarget $target): HorizonProbe
        {
            $this->probed[] = [
                'url' => $target->dashboardUrl,
                'username' => $target->username,
                'password' => $target->password(),
            ];

            if ($this->outcome instanceof Throwable) {
                throw $this->outcome;
            }

            return $this->outcome;
        }
    };

    $this->app->instance(HorizonReader::class, $this->reader);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $this->viewer = User::factory()->create();
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Invoicer']);

    $this->production = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://invoicer.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'stored-secret-value',
    ]);

    $this->staging = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'horizon_url' => 'https://staging.invoicer.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'stored-staging-secret',
    ]);

    $this->environmentUrl = fn (Environment $environment): string => route('environments.test-connection', [
        'current_team' => $this->team->slug,
        'environment' => $environment->slug,
    ]);

    $this->applicationUrl = route('applications.test-connection', ['current_team' => $this->team->slug]);
});

test('a member tests a watched environment with its saved address and credentials', function () {
    $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertOk()
        ->assertExactJson([
            'reachable' => true,
            'horizonStatus' => 'running',
            'masterCount' => 2,
            'latencyMs' => 84,
            'error' => null,
        ]);

    expect($this->reader->probed)->toBe([[
        'url' => 'https://staging.invoicer.example.com/horizon',
        'username' => 'monitor',
        'password' => 'stored-staging-secret',
    ]]);
});

test('a member gets 404 on an environment hidden from them, and nothing is contacted', function () {
    $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->production))
        ->assertNotFound();

    expect($this->reader->probed)->toBe([]);
});

test('a member may not test an address of their own through a saved environment', function () {
    $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->staging), [
            'horizonUrl' => 'http://10.0.0.5/horizon',
        ])
        ->assertForbidden();

    expect($this->reader->probed)->toBe([]);
});

test('a viewer cannot test a watched environment, and a hidden one still answers 404', function () {
    $this->actingAs($this->viewer)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertForbidden();

    $viewer = User::factory()->create();
    $this->team->members()->attach($viewer, [
        'role' => TeamRole::Viewer->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $this->actingAs($viewer)
        ->postJson(($this->environmentUrl)($this->production))
        ->assertNotFound();

    expect($this->reader->probed)->toBe([]);
});

test('an admin testing the edit form with a blank password uses the stored one on its own address, and never sends it back', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), [
            'horizonUrl' => 'HTTPS://Invoicer.example.com:443/ops/horizon',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => '',
        ])
        ->assertOk()
        ->assertJsonPath('reachable', true);

    expect($this->reader->probed)->toBe([[
        'url' => 'HTTPS://Invoicer.example.com:443/ops/horizon',
        'username' => 'monitor',
        'password' => 'stored-secret-value',
    ]])
        ->and($response->getContent())->not->toContain('stored-secret-value')
        ->and(array_keys($response->json()))->toBe(['reachable', 'horizonStatus', 'masterCount', 'latencyMs', 'error']);
});

test('a blank password is refused when the test goes to another address or username', function (array $payload) {
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), [...$payload, 'basicAuthPassword' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['basicAuthPassword' => 'Type the password again']);

    expect($this->reader->probed)->toBe([]);
})->with([
    'another host' => [['horizonUrl' => 'https://attacker.example.net/collect', 'basicAuthUser' => 'monitor']],
    'another scheme' => [['horizonUrl' => 'http://invoicer.example.com/horizon', 'basicAuthUser' => 'monitor']],
    'another port' => [['horizonUrl' => 'https://invoicer.example.com:8443/horizon', 'basicAuthUser' => 'monitor']],
    'a subdomain' => [['horizonUrl' => 'https://evil.invoicer.example.com/horizon', 'basicAuthUser' => 'monitor']],
    'another username' => [['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthUser' => 'someone-else']],
]);

test('a typed password may be tested anywhere', function () {
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), [
            'horizonUrl' => 'https://elsewhere.example.net/horizon',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => 'typed-secret',
        ])
        ->assertOk();

    expect($this->reader->probed)->toBe([
        ['url' => 'https://elsewhere.example.net/horizon', 'username' => 'monitor', 'password' => 'typed-secret'],
    ]);
});

test('a test that moves the stored credential to another address or username needs the credentials permission', function () {
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    $url = ($this->environmentUrl)($this->production);

    $this->actingAs($this->admin)
        ->postJson($url, ['horizonUrl' => 'https://invoicer.example.com/ops/horizon', 'basicAuthUser' => 'monitor'])
        ->assertOk();
    $this->actingAs($this->admin)
        ->postJson($url, ['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthUser' => 'monitor', 'basicAuthPassword' => 'typed'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson($url, ['horizonUrl' => 'https://elsewhere.example.net/horizon', 'basicAuthUser' => 'monitor', 'basicAuthPassword' => 'typed'])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->postJson($url, ['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthUser' => 'other', 'basicAuthPassword' => 'typed'])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->postJson($url, ['horizonUrl' => 'https://invoicer.example.com/horizon'])
        ->assertForbidden();

    $bare = Environment::factory()->for($this->application)->create([
        'name' => 'develop',
        'horizon_url' => 'https://develop.invoicer.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($bare), ['horizonUrl' => 'https://elsewhere.example.net/horizon'])
        ->assertOk();

    expect($this->reader->probed)->toHaveCount(3);
});

test('the target never pairs the stored password with another address or username, even unvalidated', function () {
    $target = fn (array $payload) => TestConnectionData::from(['basicAuthPassword' => null, ...$payload])->target($this->production);

    expect($target(['horizonUrl' => 'https://attacker.example.net/collect', 'basicAuthUser' => 'monitor'])->password())->toBeNull()
        ->and($target(['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthUser' => 'other'])->password())->toBeNull()
        ->and($target(['horizonUrl' => 'https://invoicer.example.com/elsewhere', 'basicAuthUser' => 'monitor'])->password())->toBe('stored-secret-value');
});

test('the edit form test uses a typed password, and no username means no credential at all', function () {
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), [
            'horizonUrl' => 'https://invoicer.example.com/horizon',
            'basicAuthUser' => 'another',
            'basicAuthPassword' => 'typed-secret',
        ])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), [
            'horizonUrl' => 'https://invoicer.example.com/horizon',
            'basicAuthUser' => null,
            'basicAuthPassword' => null,
        ])
        ->assertOk();

    expect($this->reader->probed)->toBe([
        ['url' => 'https://invoicer.example.com/horizon', 'username' => 'another', 'password' => 'typed-secret'],
        ['url' => 'https://invoicer.example.com/horizon', 'username' => null, 'password' => null],
    ]);
});

test('an admin may test an environment hidden from their own wall through the edit form', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $this->actingAs($admin)
        ->postJson(($this->environmentUrl)($this->production), [
            'horizonUrl' => 'https://invoicer.example.com/horizon',
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson(($this->environmentUrl)($this->production))
        ->assertNotFound();
});

test('the edit form test refuses what the form itself would refuse', function (array $payload, string $field) {
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($this->reader->probed)->toBe([]);
})->with([
    'no url' => [['basicAuthUser' => 'monitor'], 'horizonUrl'],
    'not http' => [['horizonUrl' => 'ftp://invoicer.example.com/horizon'], 'horizonUrl'],
    'colon in the username' => [['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthUser' => 'a:b'], 'basicAuthUser'],
    'password without username' => [['horizonUrl' => 'https://invoicer.example.com/horizon', 'basicAuthPassword' => 'x'], 'basicAuthUser'],
    'credentials in the url' => [['horizonUrl' => 'https://ops:url-secret@invoicer.example.com/horizon'], 'horizonUrl'],
]);

test('another organization\'s environment slug does not resolve', function () {
    $otherTeam = Team::factory()->create();
    $otherApplication = Application::factory()->for($otherTeam)->create(['name' => 'Elsewhere']);
    $other = Environment::factory()->for($otherApplication)->create(['name' => 'production']);

    $this->actingAs($this->admin)
        ->postJson(route('environments.test-connection', [
            'current_team' => $this->team->slug,
            'environment' => $other->slug,
        ]), ['horizonUrl' => 'https://invoicer.example.com/horizon'])
        ->assertNotFound();

    expect($this->reader->probed)->toBe([]);
});

test('an admin tests an unsaved address from the wizard', function () {
    $this->actingAs($this->admin)
        ->postJson($this->applicationUrl, [
            'horizonUrl' => 'https://shop.example.com/horizon',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => 'typed-secret',
        ])
        ->assertOk()
        ->assertJsonPath('masterCount', 2);

    expect($this->reader->probed)->toBe([[
        'url' => 'https://shop.example.com/horizon',
        'username' => 'monitor',
        'password' => 'typed-secret',
    ]]);
});

test('the unsaved-address test needs the permission that creates applications', function (string $role) {
    $user = $role === 'member' ? $this->member : $this->viewer;

    $this->actingAs($user)
        ->postJson($this->applicationUrl, ['horizonUrl' => 'https://shop.example.com/horizon'])
        ->assertForbidden();

    expect($this->reader->probed)->toBe([]);
})->with(['member', 'viewer']);

test('the unsaved-address test wants both halves of basic auth, as creating would', function () {
    $this->actingAs($this->admin)
        ->postJson($this->applicationUrl, ['horizonUrl' => 'https://shop.example.com/horizon', 'basicAuthUser' => 'monitor'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('basicAuthPassword');

    expect($this->reader->probed)->toBe([]);
});

test('the unsaved-address test requires a body', function () {
    $this->actingAs($this->admin)
        ->postJson($this->applicationUrl)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('horizonUrl');
});

test('a failed probe is a 200 with the reason and nothing else', function (ReadingError $reason) {
    $this->reader->outcome = new HorizonReadFailed($reason);

    $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertOk()
        ->assertExactJson([
            'reachable' => false,
            'horizonStatus' => null,
            'masterCount' => null,
            'latencyMs' => null,
            'error' => $reason->value,
        ]);
})->with(ReadingError::cases());

test('an unexpected reader failure reads as unreachable and reports nothing it carried', function () {
    Exceptions::fake();

    $this->reader->outcome = new RuntimeException('cURL error for https://monitor:stored-staging-secret@staging.invoicer.example.com');

    $response = $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertOk()
        ->assertJsonPath('error', ReadingError::Unreachable->value);

    expect($response->getContent())->not->toContain('stored-staging-secret');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), 'secret')
        && $exception->getPrevious() === null);
});

test('the eleventh test in a minute is refused, across both routes', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->actingAs($this->admin)->postJson(($this->environmentUrl)($this->staging))->assertOk();
        $this->actingAs($this->admin)
            ->postJson($this->applicationUrl, ['horizonUrl' => 'https://shop.example.com/horizon'])
            ->assertOk();
    }

    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertTooManyRequests();

    $this->actingAs($this->member)
        ->postJson(($this->environmentUrl)($this->staging))
        ->assertOk();

    expect($this->reader->probed)->toHaveCount(11);
});

test('a connection test writes no reading and does not touch the polling schedule', function () {
    $before = $this->staging->fresh()->only(['last_polled_at', 'next_poll_at', 'updated_at']);

    $this->actingAs($this->member)->postJson(($this->environmentUrl)($this->staging))->assertOk();
    $this->actingAs($this->admin)
        ->postJson(($this->environmentUrl)($this->production), ['horizonUrl' => 'https://invoicer.example.com/horizon'])
        ->assertOk();
    $this->actingAs($this->admin)
        ->postJson($this->applicationUrl, ['horizonUrl' => 'https://shop.example.com/horizon'])
        ->assertOk();

    expect(EnvironmentSnapshot::count())->toBe(0)
        ->and(EnvironmentState::count())->toBe(0)
        ->and($this->staging->fresh()->only(['last_polled_at', 'next_poll_at', 'updated_at']))->toEqual($before);
});

test('a failed validation does not flash the typed password into the session', function () {
    $this->actingAs($this->admin)
        ->from('/somewhere')
        ->post($this->applicationUrl, [
            'horizonUrl' => 'not a url',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => 'typed-secret',
        ])
        ->assertRedirect('/somewhere')
        ->assertSessionHasErrors('horizonUrl');

    expect(session()->getOldInput('basicAuthPassword'))->toBeNull()
        ->and(session()->getOldInput('basicAuthUser'))->toBe('monitor');
});

test('neither route probes a URL that carries credentials, nor flashes it back', function (string $route) {
    $url = $route === 'environment' ? ($this->environmentUrl)($this->production) : $this->applicationUrl;
    $payload = ['horizonUrl' => 'https://ops:url-secret@shop.example.com/horizon'];

    $this->actingAs($this->admin)
        ->postJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['horizonUrl' => 'basic-auth fields']);

    $this->actingAs($this->admin)
        ->from('/somewhere')
        ->post($url, $payload)
        ->assertRedirect('/somewhere')
        ->assertSessionHasErrors('horizonUrl');

    expect($this->reader->probed)->toBe([])
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('url-secret');
})->with(['environment', 'application']);

test('the master status of a probe is handed out as a Horizon status', function (string $reported, ?HorizonStatus $status) {
    $this->reader->outcome = new HorizonProbe(status: $reported, masterCount: 1, latencyMs: 10);

    expect(app(TestConnection::class)->handle(new HorizonTarget('https://staging.example.com/horizon', null, null))->horizonStatus)
        ->toBe($status);
})->with([
    ['running', HorizonStatus::Running],
    ['paused', HorizonStatus::Paused],
    ['inactive', HorizonStatus::Inactive],
    ['exploded', null],
]);
