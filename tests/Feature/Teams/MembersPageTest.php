<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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
    $this->team->memberships()
        ->where('user_id', $this->member->id)
        ->update(['visibility' => MemberVisibility::Manual->value]);

    DB::table('environment_user')->insert([
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);

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
    $this->team->memberships()
        ->where('user_id', $this->member->id)
        ->update(['visibility' => MemberVisibility::Manual->value]);

    DB::table('environment_user')->insert([
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);

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
    $this->team->memberships()
        ->where('user_id', $this->member->id)
        ->update(['visibility' => MemberVisibility::Manual->value]);

    DB::table('environment_user')->insert([
        'user_id' => $this->member->id,
        'environment_id' => $this->staging->id,
    ]);

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
