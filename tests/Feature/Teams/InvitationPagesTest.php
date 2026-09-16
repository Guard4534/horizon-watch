<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
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
            ->where('page.organizationName', 'Acme Group')
            ->where('page.roleLabel', 'Member')
            ->where('page.visibilityLabel', 'All environments')
            ->where('page.email', 'invited@example.com'));
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
            ->where('page.email', null));
})->with([
    'expired' => ['expired', 'expired'],
    'revoked' => ['revoked', 'revoked'],
    'accepted' => ['accepted', 'accepted'],
]);

test('an unknown code is a 404, not an empty invitation page', function () {
    $this->get(route('invitations.show', 'does-not-exist'))->assertNotFound();
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
