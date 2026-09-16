<?php

use App\Actions\Teams\AcceptInvitation;
use App\Actions\Teams\RegisterInvitedUser;
use App\Data\Teams\AcceptInvitationData;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

test('only admin and owner can send invitations', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $this->team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    $this->actingAs($member)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited-by-member@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited-by-viewer@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('team_invitations', 0);
});

test('an invitation records role, visibility and a 7-day expiry, and emails a link with the code', function () {
    Notification::fake();
    $this->travelTo(now());

    $this->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Admin->value,
            'visibility' => MemberVisibility::NonProduction->value,
        ])
        ->assertRedirect();

    $invitation = TeamInvitation::where('email', 'invited@example.com')->firstOrFail();

    expect($invitation->role)->toBe(TeamRole::Admin)
        ->and($invitation->visibility)->toBe(MemberVisibility::NonProduction)
        ->and($invitation->expires_at->timestamp)->toBe(now()->addDays(7)->timestamp);

    Notification::assertSentOnDemand(
        TeamInvitationNotification::class,
        function ($notification, $channels, $notifiable) use ($invitation) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === $invitation->email
                && str_contains($mail->actionUrl, $invitation->code);
        },
    );
});

test('manual visibility without environments is rejected', function () {
    $this->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Viewer->value,
            'visibility' => MemberVisibility::Manual->value,
        ])
        ->assertSessionHasErrors('environmentIds');
});

test('environment ids from another organization are rejected', function () {
    $otherEnvironment = Environment::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Viewer->value,
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [$otherEnvironment->id],
        ])
        ->assertSessionHasErrors('environmentIds.0');
});

test('manual visibility invites copy the chosen environments on acceptance', function () {
    Notification::fake();

    $application = Application::factory()->for($this->team)->create();
    $kept = Environment::factory()->for($application)->create(['name' => 'staging']);
    $discarded = Environment::factory()->for($application)->create(['name' => 'production']);

    $this->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Viewer->value,
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [$kept->id],
        ])
        ->assertRedirect();

    $invitation = TeamInvitation::where('email', 'invited@example.com')->firstOrFail();
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->code))
        ->assertRedirect(route('wall', ['current_team' => $this->team->slug]));

    $membership = $this->team->memberships()->where('user_id', $user->id)->firstOrFail();

    expect($membership->visibleEnvironments()->pluck('environments.id')->all())
        ->toBe([$kept->id])
        ->not->toContain($discarded->id);
});

test('accepting an invitation leaves the manual visibility of other organizations alone', function () {
    Notification::fake();

    // The same person, already a restricted member somewhere else:
    // environment_user is keyed by user_id, so a careless sync() on
    // acceptance would drop the grant they hold in the other organization.
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $otherTeam = Team::factory()->create();
    $otherApplication = Application::factory()->for($otherTeam)->create();
    $otherEnvironment = Environment::factory()->for($otherApplication)->create(['name' => 'production']);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Viewer->value, 'visibility' => MemberVisibility::Manual->value]);
    $otherMembership = $otherTeam->memberships()->where('user_id', $user->id)->firstOrFail();
    $otherMembership->visibleEnvironments()->attach($otherEnvironment);

    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->create(['name' => 'staging']);

    $this->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Viewer->value,
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [$environment->id],
        ])
        ->assertRedirect();

    $invitation = TeamInvitation::where('email', 'invited@example.com')->firstOrFail();

    $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->code))
        ->assertRedirect(route('wall', ['current_team' => $this->team->slug]));

    expect($otherMembership->visibleEnvironments()->pluck('environments.id')->all())
        ->toBe([$otherEnvironment->id])
        ->and($this->team->memberships()->where('user_id', $user->id)->firstOrFail()
            ->visibleEnvironments()->pluck('environments.id')->all())
        ->toBe([$environment->id]);
});

test('a guest opens the invitation, sees it is open, and registers to accept it', function () {
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
            ->where('page.email', 'invited@example.com')
            ->where('page.organizationName', $this->team->name));

    $response = $this->post(route('invitations.register', $invitation->code), [
        'name' => 'Ivy Guest',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ]);

    $response->assertRedirect(route('wall', ['current_team' => $this->team->slug]));

    $user = User::where('email', 'invited@example.com')->firstOrFail();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->teamRole($this->team))->toBe(TeamRole::Member);

    $this->assertAuthenticatedAs($user);
});

test('public registration stays closed even with a valid invitation', function () {
    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get('/register')->assertNotFound();
});

test('an authenticated user with the same email accepts the invitation', function () {
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Admin,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($invitedUser)->post(route('invitations.accept', $invitation->code));

    $response->assertRedirect(route('wall', ['current_team' => $this->team->slug]));

    expect($invitedUser->fresh()->belongsToTeam($this->team))->toBeTrue()
        ->and($invitedUser->fresh()->teamRole($this->team))->toBe(TeamRole::Admin)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by)->toBe($invitedUser->id);
});

test('an authenticated user with the same email declines the invitation', function () {
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($invitedUser)->delete(route('invitations.decline', $invitation->code));

    $response->assertRedirect(route('wall', ['current_team' => $invitedUser->currentTeam->slug]));

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    expect($invitedUser->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('an authenticated user with a different email sees wrong_account and cannot accept', function () {
    $otherUser = User::factory()->create(['email' => 'someone-else@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($otherUser)
        ->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'wrong_account')
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null));

    $this->actingAs($otherUser)
        ->post(route('invitations.accept', $invitation->code))
        ->assertForbidden();

    expect($otherUser->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('decline is gated like accept: an invitation that is no longer open cannot be declined', function () {
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($user)
        ->delete(route('invitations.decline', $invitation->code))
        ->assertStatus(410);

    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('an expired invitation reports its state and rejects acceptance', function () {
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'expired')
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null));

    $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->code))
        ->assertStatus(410);
});

test('a revoked invitation reports its state and rejects acceptance', function () {
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->revoked()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'revoked')
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null));

    $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->code))
        ->assertStatus(410);
});

test('an already accepted invitation reports its state and rejects acceptance', function () {
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'accepted')
            ->where('page.organizationName', null)
            ->where('page.roleLabel', null)
            ->where('page.visibilityLabel', null)
            ->where('page.email', null));

    $this->actingAs($user)
        ->post(route('invitations.accept', $invitation->code))
        ->assertStatus(410);
});

test('registering against an expired invitation is rejected', function () {
    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->post(route('invitations.register', $invitation->code), [
        'name' => 'Ivy Guest',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ])->assertStatus(410);

    $this->assertDatabaseMissing('users', ['email' => 'invited@example.com']);
});

test('registering an address that already has an account is refused, and the page says to sign in', function () {
    $existing = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    // The invitation is open, but not to a guest who cannot be this
    // account: stateFor() answers sign_in_required, which register()'s
    // abort_unless(state === 'open') turns into a 410 before
    // RegisterInvitedUser (and its 409 race guard) is ever reached.
    $this->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'sign_in_required'));

    $this->post(route('invitations.register', $invitation->code), [
        'name' => 'Ivy Guest',
        'password' => 'password1234',
        'password_confirmation' => 'password1234',
    ])->assertStatus(410);

    expect(User::where('email', 'invited@example.com')->count())->toBe(1)
        ->and($existing->fresh()->belongsToTeam($this->team))->toBeFalse();

    // Signing in turns the same link into the ordinary accept page.
    $this->actingAs($existing)
        ->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'open')
            ->where('page.authenticated', true));
});

test('resend regenerates only the expiry, keeps the code, and respects the rate limit', function () {
    Notification::fake();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDay(),
    ]);
    $originalCode = $invitation->code;

    $this->travelTo(now()->addHour());

    $this->actingAs($this->owner)
        ->post(route('members.invitations.resend', ['current_team' => $this->team->slug, 'invitation' => $invitation->id]))
        ->assertRedirect();

    $invitation->refresh();

    expect($invitation->code)->toBe($originalCode)
        ->and($invitation->expires_at->timestamp)->toBe(now()->addDays(7)->timestamp);

    // throttle:6,1 on this route: one request already sent above, five more
    // here reach the limit, and the seventh is blocked.
    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($this->owner)
            ->post(route('members.invitations.resend', ['current_team' => $this->team->slug, 'invitation' => $invitation->id]));
    }

    $this->actingAs($this->owner)
        ->post(route('members.invitations.resend', ['current_team' => $this->team->slug, 'invitation' => $invitation->id]))
        ->assertStatus(429);
});

test('destroy revokes the invitation, which then reports state revoked', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->owner)
        ->delete(route('members.invitations.destroy', ['current_team' => $this->team->slug, 'invitation' => $invitation->id]))
        ->assertRedirect();

    expect($invitation->fresh()->isRevoked())->toBeTrue();

    $this->get(route('invitations.show', $invitation->code))
        ->assertInertia(fn (Assert $page) => $page->where('page.state', 'revoked'));
});

test('accepting the same invitation twice creates only one membership', function () {
    $user = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $action = app(AcceptInvitation::class);
    $action->handle($invitation, $user);
    $action->handle($invitation->fresh(), $user);

    expect($this->team->memberships()->where('user_id', $user->id)->count())->toBe(1);
});

test('registering the same invitation twice does not create two users', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $data = new AcceptInvitationData('Ivy Guest', 'password1234');
    $registerInvitedUser = app(RegisterInvitedUser::class);
    $acceptInvitation = app(AcceptInvitation::class);

    $registerInvitedUser->handle($invitation, $data, $acceptInvitation);

    // Both calls start from the same (stale, pre-acceptance) invitation
    // instance, simulating two simultaneous submissions of the same form
    // that both read "no account yet" before either committed. The second
    // must abort cleanly (409), not race the email's unique constraint
    // into a raw QueryException.
    $secondAttemptStatus = null;

    try {
        $registerInvitedUser->handle($invitation, $data, $acceptInvitation);
    } catch (HttpException $exception) {
        $secondAttemptStatus = $exception->getStatusCode();
    }

    expect($secondAttemptStatus)->toBe(409)
        ->and(User::where('email', 'invited@example.com')->count())->toBe(1);
});
