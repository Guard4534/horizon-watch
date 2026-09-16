<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create(['name' => 'Acme Group']);
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

test('a guest sees the registration state with the organization, the role and the visibility', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'visibility' => MemberVisibility::All,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Invitation')
            ->where('page.state', 'open')
            ->where('page.authenticated', false)
            ->where('page.code', $invitation->code)
            ->where('page.organizationName', 'Acme Group')
            ->where('page.roleLabel', 'Member')
            ->where('page.visibilityLabel', 'All environments')
            ->where('page.email', 'invited@example.com')
            // "All environments" says it all; naming the organization's
            // environments to someone who hasn't joined it would not.
            ->where('page.visibleEnvironmentNames', []));
});

test('the invited user, already signed in, sees the accept and decline state', function () {
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Admin,
        'visibility' => MemberVisibility::NonProduction,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($invitedUser)
        ->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Invitation')
            ->where('page.state', 'open')
            ->where('page.authenticated', true)
            ->where('page.organizationName', 'Acme Group')
            ->where('page.roleLabel', 'Admin')
            ->where('page.visibilityLabel', 'Everything except production')
            ->where('page.email', 'invited@example.com'));
});

test('someone signed in with another address sees only the wrong account message', function () {
    $otherUser = User::factory()->create(['email' => 'someone-else@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($otherUser)
        ->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Invitation')
            ->where('page.state', 'wrong_account')
            ->where('page.authenticated', true)
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null));
});

// The page renders these three states from their message and a link to the
// login page alone: the four detail props are null, so anything built from
// them would be empty — and nothing leaks about the organization either.
test('a closed invitation says only what happened', function (string $factoryState, string $expectedState) {
    // No expires_at here on purpose: the expired state sets its own, and a
    // null expiry never expires, so the revoked and accepted rows are closed
    // by their own state and by nothing else.
    $invitation = TeamInvitation::factory()->{$factoryState}()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Invitation')
            ->where('page.state', $expectedState)
            ->where('page.authenticated', false)
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null)
            ->where('page.visibleEnvironmentNames', []));
})->with([
    'expired' => ['expired', 'expired'],
    'revoked' => ['revoked', 'revoked'],
    'accepted' => ['accepted', 'accepted'],
]);

test('an unknown code is a 404, not an empty invitation page', function () {
    $this->get(route('invitations.show', 'does-not-exist'))->assertNotFound();
});

test('a manual invitation names the environments the invitee will see', function () {
    $application = Application::factory()->for($this->team)->create();
    $staging = Environment::factory()->for($application)->create(['name' => 'staging']);
    Environment::factory()->for($application)->create(['name' => 'production']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Viewer,
        'visibility' => MemberVisibility::Manual,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);
    $invitation->environments()->sync([$staging->id]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'open')
            ->where('page.visibilityLabel', 'Manual selection')
            ->where('page.visibleEnvironmentNames', ['staging']));
});

test('a guest whose address already has an account is asked to sign in, not to register', function () {
    User::factory()->create(['email' => 'Invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Invitation')
            // The account differs only in case, and is still theirs.
            ->where('page.state', 'sign_in_required')
            ->where('page.authenticated', false)
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null)
            ->where('page.visibleEnvironmentNames', []));
});

// The starter kit passed a "teamInvitation" banner to the login page from an
// ?invitation= query string; invitations now have their own page, so the
// login page knows nothing about them.
test('the login page carries no invitation context', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('login', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->missing('teamInvitation'));
});
