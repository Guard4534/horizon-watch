<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Grants;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->transfer = fn (User $actor, User $target) => $this->actingAs($actor)
        ->from(route('members.index', ['current_team' => $this->team->slug]))
        ->post(route('members.transfer-ownership', ['current_team' => $this->team->slug, 'user' => $target->id]));
});

test('the owner hands the organization to another member and stays on as an admin', function () {
    ($this->transfer)($this->owner, $this->member)
        ->assertRedirect(route('members.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => "{$this->member->name} is now the owner of the organization."]);

    expect($this->member->fresh()->teamRole($this->team))->toBe(TeamRole::Owner)
        ->and($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Admin)
        ->and($this->team->memberships()->where('role', TeamRole::Owner->value)->count())->toBe(1);
});

test('the new owner sees every environment, whatever their visibility was', function () {
    $environment = Environment::factory()->create([
        'application_id' => Application::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->team->memberships()->where('user_id', $this->member->id)->update(['visibility' => MemberVisibility::Manual->value]);
    Grants::give($this->member->id, $environment->id);

    ($this->transfer)($this->owner, $this->member)->assertRedirect();

    expect($this->team->memberships()->where('user_id', $this->member->id)->value('visibility'))->toBe(MemberVisibility::All)
        ->and(DB::table('environment_user')->where('user_id', $this->member->id)->exists())->toBeFalse();
});

test('the new owner can delete the organization and the previous one cannot', function () {
    ($this->transfer)($this->owner, $this->admin)->assertRedirect();

    $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name])
        ->assertRedirect(route('teams.index'));

    $this->assertSoftDeleted('teams', ['id' => $this->team->id]);
});

test('only the owner can transfer the ownership', function (string $actor) {
    ($this->transfer)($this->{$actor}, $this->member)->assertForbidden();

    expect($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Owner)
        ->and($this->member->fresh()->teamRole($this->team))->toBe(TeamRole::Member);
})->with(['admin', 'member']);

test('the owner cannot transfer the ownership to themselves', function () {
    ($this->transfer)($this->owner, $this->owner)->assertForbidden();

    expect($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Owner);
});

test('the ownership cannot go to someone outside the organization', function () {
    $outsider = User::factory()->create();

    ($this->transfer)($this->owner, $outsider)->assertNotFound();

    expect($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Owner)
        ->and($outsider->fresh()->belongsToTeam($this->team))->toBeFalse();
});

test('a former owner can leave once the ownership is transferred', function () {
    ($this->transfer)($this->owner, $this->admin)->assertRedirect();

    $this->actingAs($this->owner)
        ->delete(route('teams.leave', $this->team))
        ->assertRedirect(route('teams.index'));

    expect($this->owner->fresh()->belongsToTeam($this->team))->toBeFalse();
});
