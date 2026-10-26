<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Tests\Support\Grants;

test('the teams index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('teams can be created', function () {
    $user = User::factory()->instanceAdmin()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Test Team',
        ]);

    $response->assertRedirect(route('teams.index'));

    $this->assertDatabaseHas('teams', ['name' => 'Test Team']);
});

test('team slug uses next available suffix', function () {
    $user = User::factory()->instanceAdmin()->create();

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('a trashed team still reserves its slug', function () {
    $user = User::factory()->instanceAdmin()->create();

    Team::factory()->trashed()->create(['name' => 'Acme', 'slug' => 'acme']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Acme']);

    $this->assertDatabaseHas('teams', ['name' => 'Acme', 'slug' => 'acme-1']);
});

test('teams can be updated by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('teams.index'));

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
        'slug' => $team->slug,
    ]);
});

test('renaming an organization keeps its slug, so links already sent keep working', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => 'Acme Corporation'])
        ->assertRedirect(route('teams.index'));

    expect($team->fresh()->slug)->toBe('acme');

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => 'acme']))
        ->assertOk();
});

test('a name without letters or digits still gets a usable slug', function () {
    $user = User::factory()->instanceAdmin()->withoutOrganization()->create();

    $this->actingAs($user)->post(route('teams.store'), ['name' => '!!!']);
    $this->actingAs($user)->post(route('teams.store'), ['name' => '???']);

    $slugs = Team::query()->whereIn('name', ['!!!', '???'])->orderBy('id')->pluck('slug')->all();

    expect($slugs)->toBe(['organization', 'organization-1'])
        ->and(array_intersect($slugs, config()->array('reserved-team-names')))->toBe([]);

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Organization'])
        ->assertSessionHasNoErrors();

    $team = Team::query()->where('name', '!!!')->firstOrFail();

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $team->slug]))
        ->assertOk();
});

test('a long name gets a slug that fits its column, suffix included', function () {
    $user = User::factory()->instanceAdmin()->create();
    $name = str_repeat('a', 255);

    $this->actingAs($user)->post(route('teams.store'), ['name' => $name])->assertValid();
    $this->actingAs($user)->post(route('teams.store'), ['name' => $name])->assertValid();

    $slugs = Team::query()->where('name', $name)->orderBy('id')->pluck('slug')->all();

    expect($slugs)->toBe([str_repeat('a', 240), str_repeat('a', 240).'-1']);
});

test('a name whose slug is a reserved address is refused', function () {
    $user = User::factory()->instanceAdmin()->create();

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Settings!'])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseMissing('teams', ['name' => 'Settings!']);
});

test('teams cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('teams can be deleted by owners', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect(route('teams.index'));

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);
});

test('team deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $zuluTeam), [
            'name' => $zuluTeam->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting non current team leaves current team unchanged', function () {
    $user = User::factory()->create();
    $keptTeam = $user->currentTeam;
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($keptTeam->id);
});

test('deleting the only organization leaves the owner without a current one', function () {
    $user = User::factory()->create();
    $user->teamMemberships()->delete();

    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    expect($user->fresh()->current_team_id)->toBeNull();
});

test('members can leave an organization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $team));

    $response->assertRedirect(route('teams.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the organization \"{$team->name}\""]);

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('leaving current team switches to alphabetically first remaining team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $zuluTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $zuluTeam->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $zuluTeam));

    $response->assertRedirect(route('teams.index'));

    expect($member->fresh()->belongsToTeam($zuluTeam))->toBeFalse();
    expect($member->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('leaving the only organization leaves the member without a current one', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->teamMemberships()->delete();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($member)
        ->delete(route('teams.leave', $team))
        ->assertRedirect(route('teams.index'));

    expect($member->fresh()->current_team_id)->toBeNull();
});

test('team owners cannot leave their team', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('users cannot leave teams they dont belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();
});

test('deleting a team moves the other affected users to an organization they still belong to', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $memberOwnTeam = $member->currentTeam;

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $owner->update(['current_team_id' => $team->id]);
    $member->update(['current_team_id' => $team->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_team_id)->toEqual($memberOwnTeam->id);
});

test('deleting a team clears the current organization of members who have no other', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->teamMemberships()->delete();

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $team->id]);

    $this
        ->actingAs($owner)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    expect($member->fresh()->current_team_id)->toBeNull();
});

test('teams cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertForbidden();
});

test('users can switch teams', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertRedirect();

    expect($user->fresh()->current_team_id)->toEqual($team->id);
});

test('switching from an organization page opens the same section of the new organization', function (string $from, string $section) {
    $user = User::factory()->create();
    $previous = $user->currentTeam;
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $application = Application::factory()->create(['team_id' => $previous->id]);
    $environment = Environment::factory()->create(['application_id' => $application->id]);

    $url = route($from, match ($from) {
        'applications.show', 'applications.edit' => ['current_team' => $previous->slug, 'application' => $application->slug],
        'environments.show' => ['current_team' => $previous->slug, 'environment' => $environment->slug],
        'alert-rules.index' => ['current_team' => $previous->slug, 'scope' => $environment->slug],
        default => ['current_team' => $previous->slug],
    });

    $this->actingAs($user)
        ->from($url)
        ->post(route('teams.switch', $team))
        ->assertRedirect(route($section, ['current_team' => $team->slug]));

    expect($user->fresh()->current_team_id)->toBe($team->id);
})->with([
    'wall' => ['wall', 'wall'],
    'an application' => ['applications.show', 'applications.index'],
    'an application form' => ['applications.edit', 'applications.index'],
    'an environment' => ['environments.show', 'applications.index'],
    'alerts' => ['alerts.index', 'alerts.index'],
    'the rules of one environment' => ['alert-rules.index', 'alert-rules.index'],
    'members' => ['members.index', 'members.index'],
    'the profile on a phone' => ['me', 'me'],
]);

test('switching from a page outside any organization stays on it', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)
        ->from(route('teams.index'))
        ->post(route('teams.switch', $team))
        ->assertRedirect(route('teams.index'));
});

test('switching with no page to come back to opens the wall of the new organization', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)
        ->from('/'.$user->currentTeam->slug.'/no-such-page')
        ->post(route('teams.switch', $team))
        ->assertRedirect(route('wall', ['current_team' => $team->slug]));
});

test('switching with a malformed previous address still opens the wall of the new organization', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)
        ->withHeader('Referer', 'http://bad host/'.$user->currentTeam->slug.'/wall')
        ->post(route('teams.switch', $team))
        ->assertRedirect(route('wall', ['current_team' => $team->slug]));

    expect($user->fresh()->current_team_id)->toBe($team->id);
});

test('users cannot switch to team they dont belong to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertForbidden();
});

test('guests cannot access teams', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});

test('the organizations page carries no invitation detail at all', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
        'email' => 'invited@example.com',
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($viewer)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertDontSee($invitation->email)
        ->assertDontSee($invitation->plainCode, escape: false);
});

test('deleting an organization deletes its applications, environments and environment grants', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $application = Application::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->production()->create([
        'application_id' => $application->id,
    ]);

    expect($environment->basic_auth_password)->not->toBeNull();

    Grants::give($member->id, $environment->id);

    $this->actingAs($owner)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    $this->assertDatabaseMissing('applications', ['id' => $application->id]);
    $this->assertDatabaseMissing('environments', ['id' => $environment->id]);
    $this->assertDatabaseMissing('environment_user', [
        'environment_id' => $environment->id,
        'user_id' => $member->id,
    ]);
});

test('leaving a team clears the environment grants it carried', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $environment = Environment::factory()->create([
        'application_id' => Application::factory()->create(['team_id' => $team->id])->id,
    ]);

    Grants::give($member->id, $environment->id);

    $this->actingAs($member)
        ->delete(route('teams.leave', $team))
        ->assertRedirect(route('teams.index'));

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();

    $this->assertDatabaseMissing('environment_user', [
        'user_id' => $member->id,
        'environment_id' => $environment->id,
    ]);
});

test('an admin is told when they are the last admin of an organization', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    expect($admin->toUserTeams()->firstWhere('id', $team->id)->isLastAdmin)->toBeTrue()
        ->and($admin->toUserTeam($team)->isLastAdmin)->toBeTrue()
        ->and($owner->toUserTeam($team)->isLastAdmin)->toBeFalse();

    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Admin->value]);

    expect($admin->fresh()->toUserTeams()->firstWhere('id', $team->id)->isLastAdmin)->toBeFalse()
        ->and($admin->fresh()->toUserTeam($team)->isLastAdmin)->toBeFalse();
});

test('a member is never the last admin', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($member->toUserTeam($team)->isLastAdmin)->toBeFalse();
});
