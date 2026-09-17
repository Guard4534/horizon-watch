<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// T0 gave the admin every permission except DeleteTeam, which on its own
// would let an admin demote or remove the owner and leave the organization
// ownerless. The owner is protected as a *target*, in the policy, not by an
// ad hoc check in one controller.
beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
});

test('an admin cannot change the owner role', function () {
    expect(Gate::forUser($this->admin)->allows('updateMember', [$this->team, $this->owner]))->toBeFalse();
});

test('an admin cannot remove the owner', function () {
    expect(Gate::forUser($this->admin)->allows('removeMember', [$this->team, $this->owner]))->toBeFalse();
});

test('the owner cannot change their own role', function () {
    // No transfer-of-ownership flow exists yet, so this is forbidden
    // outright rather than allowed only when another owner would remain.
    expect(Gate::forUser($this->owner)->allows('updateMember', [$this->team, $this->owner]))->toBeFalse();
});

test('the owner cannot remove themselves', function () {
    expect(Gate::forUser($this->owner)->allows('removeMember', [$this->team, $this->owner]))->toBeFalse();
});

test('an admin can still update and remove a regular member', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect(Gate::forUser($this->admin)->allows('updateMember', [$this->team, $member]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('removeMember', [$this->team, $member]))->toBeTrue();
});

test('an admin cannot demote the owner over HTTP', function () {
    $response = $this->actingAs($this->admin)
        ->patch(route('teams.members.update', [$this->team, $this->owner]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();

    expect($this->owner->fresh()->teamRole($this->team))->toBe(TeamRole::Owner);
});

test('an admin cannot remove the owner over HTTP', function () {
    $response = $this->actingAs($this->admin)
        ->delete(route('teams.members.destroy', [$this->team, $this->owner]));

    $response->assertForbidden();

    expect($this->owner->fresh()->belongsToTeam($this->team))->toBeTrue();
});
