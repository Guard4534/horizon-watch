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

test('setup creates the administrator and the organization and signs in', function () {
    $response = $this->post(route('setup.store'), setupPayload());

    $user = User::sole();
    $team = $user->currentTeam;

    expect($user->email)->toBe('ada@example.com')
        ->and($team->name)->toBe('Example Ops')
        ->and($team->is_personal)->toBeFalse()
        ->and($user->teamRole($team))->toBe(TeamRole::Owner);

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

    // The column, not the model attribute: this is the string the
    // users.email unique index holds and the string every exact comparison
    // in the app — Fortify's lookup, RegisterInvitedUser's race guard,
    // InvitationController's "already has an account" check — is matched
    // against.
    expect(DB::table('users')->where('id', $user->id)->value('email'))
        ->toBe('admin@example.com');

    $this->post(route('logout'));

    // Both spellings have to reach the same row: the one this person typed
    // at /setup, and the one an invitation to a second organization would
    // later address them by.
    foreach (['Admin@Example.com', 'admin@example.com'] as $spelling) {
        $this->post(route('login.store'), [
            'email' => $spelling,
            'password' => 'correct-horse-battery-9',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
    }
});
