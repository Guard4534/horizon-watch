<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('an instance admin can create an organization', function () {
    $admin = User::factory()->instanceAdmin()->create();

    $this->actingAs($admin)
        ->post(route('teams.store'), ['name' => 'Platform'])
        ->assertRedirect(route('teams.index'));

    $team = Team::query()->where('name', 'Platform')->sole();

    expect($admin->fresh()->teamRole($team))->toBe(TeamRole::Owner);
});

test('by default an account that is not an instance admin cannot create an organization', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $owner->currentTeam->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

    $this->actingAs($viewer)
        ->post(route('teams.store'), ['name' => 'Shadow'])
        ->assertForbidden();

    $this->assertDatabaseMissing('teams', ['name' => 'Shadow']);
});

test('everyone can create organizations when the instance allows it', function () {
    config(['horizon-watch.organization_creation' => 'everyone']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Side project'])
        ->assertRedirect(route('teams.index'));

    $this->assertDatabaseHas('teams', ['name' => 'Side project']);
});

test('an unknown creation setting falls back to admins only', function () {
    config(['horizon-watch.organization_creation' => 'anyone']);

    $this->actingAs(User::factory()->create())
        ->post(route('teams.store'), ['name' => 'Side project'])
        ->assertForbidden();
});

test('the interface offers a new organization only to those who may create one', function (bool $instanceAdmin, string $setting, bool $expected) {
    config(['horizon-watch.organization_creation' => $setting]);

    $user = User::factory()->create(['is_instance_admin' => $instanceAdmin]);

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canCreateOrganizations', $expected));
})->with([
    'instance admin' => [true, 'admins', true],
    'anyone else' => [false, 'admins', false],
    'anyone, when open to everyone' => [false, 'everyone', true],
]);

test('the instance admin role cannot be mass assigned', function () {
    $user = User::factory()->create();

    $user->update(['is_instance_admin' => true]);

    expect($user->fresh()->is_instance_admin)->toBeFalse();
});

test('upgrading makes the oldest account the instance admin', function () {
    $migration = require database_path('migrations/2026_10_13_020001_add_is_instance_admin_to_users_table.php');

    $newer = User::factory()->create(['created_at' => now()->subDay()]);
    $oldest = User::factory()->create(['created_at' => now()->subYear()]);

    $migration->down();
    $migration->up();

    expect(DB::table('users')->where('is_instance_admin', true)->pluck('id')->all())->toBe([$oldest->id])
        ->and($newer->fresh()->is_instance_admin)->toBeFalse();
});
