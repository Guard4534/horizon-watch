<?php

use App\Actions\Settings\DeleteAccount;
use App\Actions\Teams\TransferOwnership;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\SetupStatus;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->deleteAccount = fn (User $user) => $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);
});

test('the owner of an organization with other members cannot delete their account', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Viewer->value]);

    ($this->deleteAccount)($owner)
        ->assertSessionHasErrors(['account' => 'You own organizations that other people belong to: Acme. Transfer their ownership from the Members page, or delete them, before deleting your account.'])
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh())->not->toBeNull()
        ->and($owner->fresh()->ownsTeam($team))->toBeTrue();
    $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
});

test('the organizations nobody else belongs to are deleted with the account', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $environment = Environment::factory()->create([
        'application_id' => Application::factory()->create(['team_id' => $team->id])->id,
    ]);
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $user->id]);

    ($this->deleteAccount)($user)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
    $this->assertSoftDeleted('teams', ['id' => $team->id]);
    $this->assertDatabaseMissing('environments', ['id' => $environment->id]);
    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

test('leaving the organizations someone else owns keeps them and the invitations sent from them', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = $owner->currentTeam;
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    ($this->deleteAccount)($admin)->assertSessionHasNoErrors();

    expect($admin->fresh())->toBeNull()
        ->and($owner->fresh()->ownsTeam($team))->toBeTrue()
        ->and($team->memberships()->count())->toBe(1)
        ->and($invitation->fresh()->invited_by)->toBeNull()
        ->and($invitation->fresh()->isPending())->toBeTrue();

    $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
});

test('deleting the last account opens the setup again', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(SetupStatus::isComplete())->toBeTrue();

    ($this->deleteAccount)($user)->assertSessionHasNoErrors();

    expect(User::query()->exists())->toBeFalse();

    $this->get(route('setup.create'))->assertOk();
    $this->get(route('login'))->assertRedirect(route('setup.create'));
});

test('deleting an account while others remain keeps the setup closed', function () {
    User::factory()->create();
    $user = User::factory()->create();

    ($this->deleteAccount)($user)->assertSessionHasNoErrors();

    $this->get(route('setup.create'))->assertNotFound();
});

test('the profile page says which organizations block the deletion and which go with it', function () {
    $user = User::factory()->create();
    $user->currentTeam->update(['name' => 'Solo']);

    $shared = Team::factory()->create(['name' => 'Shared']);
    $shared->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $shared->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    $owned = Team::factory()->create(['name' => 'Elsewhere']);
    $owned->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);
    $owned->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accountDeletion.sharedOrganizations', ['Shared'])
            ->where('accountDeletion.soleOrganizations', ['Solo']));
});

test('a wrong password reports the password, not the organizations', function () {
    $owner = User::factory()->create();
    $owner->currentTeam->members()->attach(User::factory()->create(), ['role' => TeamRole::Viewer->value]);

    $this->actingAs($owner)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertSessionDoesntHaveErrors('account');

    expect($owner->fresh())->not->toBeNull();
});

test('the deletion checks ownership again inside its transaction', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = $owner->currentTeam;
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $stale = User::query()->findOrFail($admin->id);

    app(TransferOwnership::class)->handle($team, $owner, $admin);

    expect(fn () => app(DeleteAccount::class)->handle($stale))
        ->toThrow(ValidationException::class);

    expect($admin->fresh())->not->toBeNull()
        ->and($admin->fresh()->ownsTeam($team))->toBeTrue()
        ->and($team->memberships()->count())->toBe(2);
});
