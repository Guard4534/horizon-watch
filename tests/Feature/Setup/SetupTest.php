<?php

use App\Actions\Setup\CompleteSetup;
use App\Data\Auth\SetupData;
use App\Enums\TeamRole;
use App\Exceptions\SetupAlreadyCompleted;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function setupPayload(array $overrides = []): array
{
    return [
        'name' => 'Ada Example',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery-9',
        'password_confirmation' => 'correct-horse-battery-9',
        'organization' => 'Example Ops',
        ...$overrides,
    ];
}

test('the login page leads to setup while nobody has an account', function () {
    $this->get(route('login'))->assertRedirect(route('setup.create'));
});

test('the setup page is shown while nobody has an account', function () {
    $this->get(route('setup.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Setup'));
});

test('setup creates the administrator, who receives alert emails, and the organization and signs in', function () {
    $response = $this->post(route('setup.store'), setupPayload());

    $user = User::sole();
    $team = $user->currentTeam;

    expect($user->email)->toBe('ada@example.com')
        ->and($team->name)->toBe('Example Ops')
        ->and($user->teamRole($team))->toBe(TeamRole::Owner)
        ->and($user->is_instance_admin)->toBeTrue()
        ->and($user->alert_emails)->toBeTrue();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('wall', ['current_team' => $team->slug]));
});

test('setup is gone once an account exists', function () {
    User::factory()->create();

    $this->get(route('setup.create'))->assertNotFound();
    $this->post(route('setup.store'), setupPayload(['email' => 'other@example.com']))->assertNotFound();

    expect(User::count())->toBe(1);
});

test('the action itself refuses a second setup', function () {
    User::factory()->create();

    app(CompleteSetup::class)->handle(SetupData::from(setupPayload()));
})->throws(SetupAlreadyCompleted::class);

test('setup takes its advisory lock inside the transaction before it looks for an account', function () {
    $outer = DB::transactionLevel();
    $statements = [];

    DB::beforeExecuting(function (string $sql) use (&$statements, $outer) {
        $statements[] = [$sql, DB::transactionLevel() > $outer];
    });

    app(CompleteSetup::class)->handle(SetupData::from(setupPayload()));

    $lock = collect($statements)->search(fn (array $statement) => str_contains($statement[0], 'pg_advisory_xact_lock'));
    $lookup = collect($statements)->search(fn (array $statement) => str_starts_with($statement[0], 'select exists(select * from "users"'));

    expect($lock)->toBeInt()
        ->and($statements[$lock][1])->toBeTrue()
        ->and($lookup)->toBeInt()->toBeGreaterThan($lock);
});

test('passwords need at least ten characters and a matching confirmation', function () {
    $this->post(route('setup.store'), setupPayload(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertSessionHasErrors('password');

    $this->post(route('setup.store'), setupPayload(['password_confirmation' => 'something-else']))
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

test('nobody can sign up', function () {
    User::factory()->create();

    $this->get('/register')->assertNotFound();
    $this->post('/register', setupPayload())->assertNotFound();
});

test('the administrator address is stored canonically and signs in under either spelling', function () {
    $this->post(route('setup.store'), setupPayload(['email' => 'Admin@Example.com']))->assertRedirect();

    $user = User::sole();

    expect(DB::table('users')->where('id', $user->id)->value('email'))
        ->toBe('admin@example.com');

    $this->post(route('logout'));

    foreach (['Admin@Example.com', 'admin@example.com'] as $spelling) {
        $this->post(route('login.store'), [
            'email' => $spelling,
            'password' => 'correct-horse-battery-9',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
    }
});

test('the setup page asks for no token unless one is configured', function () {
    $this->get(route('setup.create'))
        ->assertInertia(fn (Assert $page) => $page->where('page.requiresToken', false));

    config(['horizon-watch.setup_token' => 'a-long-setup-token']);

    $this->get(route('setup.create'))
        ->assertInertia(fn (Assert $page) => $page->where('page.requiresToken', true));
});

test('a configured setup token is required to claim the panel', function (?string $token) {
    config(['horizon-watch.setup_token' => 'a-long-setup-token']);

    $this->post(route('setup.store'), setupPayload(['setupToken' => $token]))
        ->assertSessionHasErrors('setupToken')
        ->assertSessionMissing('_old_input.setupToken');

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'missing' => [null],
    'wrong' => ['another-setup-token'],
    'a prefix' => ['a-long-setup'],
]);

test('the configured setup token lets setup through', function () {
    config(['horizon-watch.setup_token' => 'a-long-setup-token']);

    $this->post(route('setup.store'), setupPayload(['setupToken' => 'a-long-setup-token']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs(User::sole());
});

test('a configured setup token shorter than 16 characters refuses every setup', function () {
    config(['horizon-watch.setup_token' => '0123456789abcde']);

    $this->post(route('setup.store'), setupPayload(['setupToken' => '0123456789abcde']))
        ->assertSessionHasErrors(['setupToken' => 'HORIZON_WATCH_SETUP_TOKEN is too short: set at least 16 characters and restart the panel.']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('a setup token sent while none is configured is ignored', function () {
    $this->post(route('setup.store'), setupPayload(['setupToken' => 'anything']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(User::count())->toBe(1);
});

test('the setup token comes from the environment, trimmed', function () {
    putenv('HORIZON_WATCH_SETUP_TOKEN=  a-long-setup-token  ');
    $_ENV['HORIZON_WATCH_SETUP_TOKEN'] = '  a-long-setup-token  ';

    try {
        $config = require base_path('config/horizon-watch.php');
    } finally {
        putenv('HORIZON_WATCH_SETUP_TOKEN');
        unset($_ENV['HORIZON_WATCH_SETUP_TOKEN']);
    }

    expect($config['setup_token'])->toBe('a-long-setup-token')
        ->and((require base_path('config/horizon-watch.php'))['setup_token'])->toBe('');
});
