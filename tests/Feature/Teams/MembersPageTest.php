<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Switch a membership to manual visibility and grant it one environment,
 * through the team-scoped relation the application itself reads.
 */
function grantManually(Team $team, User $user, Environment $environment): void
{
    $team->memberships()
        ->where('user_id', $user->id)
        ->update(['visibility' => MemberVisibility::Manual->value]);

    $team->memberships()
        ->where('user_id', $user->id)
        ->firstOrFail()
        ->visibleEnvironments()
        ->attach($environment);
}

/**
 * A second organization where the same person holds a manual grant, so a
 * write scoped to the wrong column shows up as a deleted row here.
 */
function otherOrganizationGrant(User $user): Environment
{
    $team = Team::factory()->create();
    $environment = Environment::factory()->production()->create([
        'application_id' => Application::factory()->create(['team_id' => $team->id])->id,
    ]);

    $team->members()->attach($user, ['role' => TeamRole::Viewer->value]);

    grantManually($team, $user, $environment);

    return $environment;
}

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->viewer = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $application = Application::factory()->create(['team_id' => $this->team->id, 'name' => 'Billing']);

    $this->production = Environment::factory()->production()->create(['application_id' => $application->id]);
    $this->staging = Environment::factory()->staging()->create(['application_id' => $application->id]);
});

test('the members page answers every role', function (string $role) {
    $this->actingAs($this->{$role})
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Members')
            ->has('page.members', 4));
})->with(['owner', 'admin', 'member', 'viewer']);

test('the invite form and the member menu only appear with the permission', function (
    string $role,
    bool $canInvite,
    bool $canManageMembers,
) {
    $this->actingAs($this->{$role})
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.permissions.canInvite', $canInvite)
            ->where('page.permissions.canManageMembers', $canManageMembers));
})->with([
    ['owner', true, true],
    ['admin', true, true],
    ['member', false, false],
    ['viewer', false, false],
]);

test('the page keeps the mockup last-seen column empty, because nothing records it', function () {
    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.members', fn ($members) => collect($members)
                ->every(fn (array $member) => $member['lastSeenAt'] === null)));
});

test('the permission matrix comes from the role definitions, one row per permission', function () {
    $this->actingAs($this->viewer)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(function (Assert $page) {
            $page->has('page.matrix', count(TeamPermission::cases()));

            foreach (TeamPermission::cases() as $index => $permission) {
                $page
                    ->where("page.matrix.{$index}.permission", $permission->value)
                    ->where("page.matrix.{$index}.owner", TeamRole::Owner->hasPermission($permission))
                    ->where("page.matrix.{$index}.admin", TeamRole::Admin->hasPermission($permission))
                    ->where("page.matrix.{$index}.member", TeamRole::Member->hasPermission($permission))
                    ->where("page.matrix.{$index}.viewer", TeamRole::Viewer->hasPermission($permission));
            }
        });
});

test('pending invitations and the environment picker stay out of the props without the permission', function () {
    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.invitations', 1)
            ->has('page.environments', 2));

    // An invitation carries its join code, which is a bearer token: a member
    // who cannot invite never receives one.
    $this->actingAs($this->member)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.invitations', 0)
            ->has('page.environments', 0));
});

test('a manual member lists the environments they can actually see', function () {
    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.members', fn ($members) => collect($members)
                ->firstWhere('email', $this->member->email)['visibleEnvironmentNames'] === ['Billing / staging']));
});

test('an admin changes a role', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->viewer->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertRedirect();

    expect($this->viewer->fresh()->teamRole($this->team))->toBe(TeamRole::Member);
});

test('a member cannot change a role', function () {
    $this->actingAs($this->member)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->viewer->id]), [
            'role' => TeamRole::Admin->value,
        ])
        ->assertForbidden();
});

test('the owner can be neither demoted nor removed', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->owner->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $this->owner->id]))
        ->assertForbidden();

    expect($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Owner);
});

test('the last admin besides the owner cannot demote themselves', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->admin->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertSessionHasErrors('role');

    expect($this->admin->fresh()->teamRole($this->team))->toBe(TeamRole::Admin);
});

test('an admin can demote themselves once somebody else is an admin', function () {
    $second = User::factory()->create();
    $this->team->members()->attach($second, ['role' => TeamRole::Admin->value]);

    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->admin->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertRedirect();

    expect($this->admin->fresh()->teamRole($this->team))->toBe(TeamRole::Member);
});

test('demoting another admin is allowed even when it leaves only the owner', function () {
    $this->actingAs($this->owner)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->admin->id]), [
            'role' => TeamRole::Viewer->value,
        ])
        ->assertRedirect();

    expect($this->admin->fresh()->teamRole($this->team))->toBe(TeamRole::Viewer);
});

test('manual visibility without environments is refused', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [],
        ])
        ->assertSessionHasErrors('environmentIds');

    expect($this->member->fresh()->teamRole($this->team))->toBe(TeamRole::Member)
        ->and($this->team->memberships()->where('user_id', $this->member->id)->first()->visibility)
        ->toBe(MemberVisibility::All);
});

test('manual visibility records the chosen environments', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [$this->staging->id],
        ])
        ->assertRedirect();

    expect($this->team->memberships()->where('user_id', $this->member->id)->first()->visibility)
        ->toBe(MemberVisibility::Manual);

    $this->assertDatabaseHas('environment_user', [
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);
});

test('leaving manual visibility clears the explicit environments', function () {
    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [
            'visibility' => MemberVisibility::All->value,
        ])
        ->assertRedirect();

    $this->assertDatabaseMissing('environment_user', ['user_id' => $this->member->id]);
});

test('an environment of another organization cannot be granted', function () {
    $other = Environment::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [
            'visibility' => MemberVisibility::Manual->value,
            'environmentIds' => [$other->id],
        ])
        ->assertSessionHasErrors('environmentIds.0');
});

test('an update with neither a role nor a visibility is refused', function () {
    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [])
        ->assertSessionHasErrors(['role', 'visibility']);
});

test('an admin removes a member, together with their environment grants', function () {
    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $this->member->id]))
        ->assertRedirect();

    expect($this->member->fresh()->belongsToTeam($this->team))->toBeFalse();

    $this->assertDatabaseMissing('environment_user', ['user_id' => $this->member->id]);
});

test('a viewer cannot remove anybody', function () {
    $this->actingAs($this->viewer)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $this->member->id]))
        ->assertForbidden();

    expect($this->member->fresh()->belongsToTeam($this->team))->toBeTrue();
});

test('a stranger to the organization is a 404, not a 403', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $stranger->id]), [
            'role' => TeamRole::Member->value,
        ])
        ->assertNotFound();
});

test('changing visibility leaves the manual grants of other organizations alone', function () {
    // assertDatabaseMissing('environment_user', ['user_id' => …]) passes just
    // as happily for an unscoped delete, so the scoping only becomes a fact
    // once a second organization is in the table.
    $elsewhere = otherOrganizationGrant($this->member);

    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->patch(route('members.update', ['current_team' => $this->team->slug, 'user' => $this->member->id]), [
            'visibility' => MemberVisibility::All->value,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('environment_user', [
        'user_id' => $this->member->id,
        'environment_id' => $elsewhere->id,
    ]);

    $this->assertDatabaseMissing('environment_user', [
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);
});

test('removing a member leaves the manual grants of other organizations alone', function () {
    $elsewhere = otherOrganizationGrant($this->member);

    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $this->member->id]))
        ->assertRedirect();

    expect($this->member->fresh()->belongsToTeam($this->team))->toBeFalse();

    $this->assertDatabaseHas('environment_user', [
        'user_id' => $this->member->id,
        'environment_id' => $elsewhere->id,
    ]);

    $this->assertDatabaseMissing('environment_user', [
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);
});

test('the members props carry the granted environment ids, so the dialog can reopen on them', function () {
    grantManually($this->team, $this->member, $this->staging);

    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.members', function ($members) {
                $member = collect($members)->firstWhere('email', $this->member->email);

                return $member['visibleEnvironmentIds'] === [$this->staging->id]
                    && $member['visibleEnvironmentNames'] === ['Billing / staging'];
            }));

    // Gated exactly like the names: a member who cannot manage members is
    // told nothing about which environments exist.
    $this->actingAs($this->member)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.members', fn ($members) => collect($members)
                ->every(fn (array $member) => $member['visibleEnvironmentIds'] === []
                    && $member['visibleEnvironmentNames'] === [])));
});

test('the invite form still gets the environments without the member-management permission', function () {
    // canInvite and canManageMembers are the same permission set today, so
    // this only pins the gate's shape: the picker follows either one.
    $this->actingAs($this->owner)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.permissions.canInvite', true)
            ->has('page.environments', 2));
});

test('no invitation prop carries the join code', function () {
    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]));

    $response->assertInertia(fn (Assert $page) => $page->has('page.invitations', 1));

    $code = TeamInvitation::where('team_id', $this->team->id)->firstOrFail()->code;

    // The code is the invitee's credential: it belongs in the emailed link
    // and nowhere else, props included.
    $response->assertDontSee($code, escape: false);
});

test('an admin removes themselves from the members view and lands on the home route', function () {
    $this->admin->update(['current_team_id' => $this->team->id]);

    $this->actingAs($this->admin)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $this->admin->id]))
        ->assertRedirect(route('home'));

    expect($this->admin->fresh()->belongsToTeam($this->team))->toBeFalse()
        ->and($this->admin->fresh()->current_team_id)->toEqual($this->admin->personalTeam()->id);
});

test('removing a stranger from the members view is a 404, not a false success', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('members.destroy', ['current_team' => $this->team->slug, 'user' => $stranger->id]))
        ->assertNotFound();
});

test('a role tag reads the same on the members view and on the team settings page', function () {
    // One format, one function (HasTeams::roleLabel): the lowercase enum
    // value, as the mockup writes its tags, plus the owner's sentence. These
    // two pages used to disagree — "member" here, "Member" there — under the
    // same prop name.
    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.members.0.roleLabel', 'Owner · admin')
            ->where('page.members.1.roleLabel', 'admin'));

    $this->actingAs($this->admin)
        ->get(route('teams.edit', $this->team))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members.0.role_label', 'Owner · admin')
            ->where('members.1.role_label', 'admin'));
});
