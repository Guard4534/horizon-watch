<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null, 'mail.mailers.smtp.url' => null]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $this->invitation = TeamInvitation::factory()->expiresIn(7)->create(['team_id' => $this->team->id, 'invited_by' => $this->admin->id]);

    $this->link = fn (TeamInvitation $invitation, ?Team $team = null) => $this->getJson(route('members.invitations.link', [
        'current_team' => ($team ?? $this->team)->slug,
        'invitation' => $invitation->id,
    ]));
});

test('an admin can fetch the link of a pending invitation, built from the public url', function () {
    config(['app.url' => 'https://watch.example.com']);

    $this->actingAs($this->admin);

    ($this->link)($this->invitation)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['url' => 'https://watch.example.com/invitations/'.$this->invitation->code]);
});

test('the members page never carries the invitation links', function () {
    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.invitations', 1)
            ->where('page.invitations.0.id', $this->invitation->id))
        ->assertDontSee($this->invitation->code);
});

test('once mail is set up, only an instance admin can still fetch an invitation link', function () {
    config(['mail.mailers.smtp.host' => 'smtp.example.com']);

    $this->actingAs($this->admin);

    ($this->link)($this->invitation)->assertForbidden();

    $this->admin->forceFill(['is_instance_admin' => true])->save();

    ($this->link)($this->invitation)->assertOk();
});

test('the members page says whether the admin may copy invitation links', function () {
    $page = fn () => $this->actingAs($this->admin)->get(route('members.index', ['current_team' => $this->team->slug]));

    $page()->assertInertia(fn (Assert $page) => $page->where('page.permissions.canCopyInvitationLinks', true));

    config(['mail.mailers.smtp.host' => 'smtp.example.com']);

    $page()->assertInertia(fn (Assert $page) => $page->where('page.permissions.canCopyInvitationLinks', false));
});

test('members and viewers cannot fetch an invitation link', function (TeamRole $role) {
    $user = User::factory()->create();
    $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);

    $this->actingAs($user);

    ($this->link)($this->invitation)->assertForbidden();
})->with([TeamRole::Member, TeamRole::Viewer]);

test('an invitation of another organization has no link here', function () {
    $other = Team::factory()->create();
    $other->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $foreign = TeamInvitation::factory()->expiresIn(7)->create(['team_id' => $other->id]);

    $this->actingAs($this->admin);

    ($this->link)($foreign)->assertNotFound();
    ($this->link)($foreign, $other)->assertOk();
});

test('an invitation that is no longer pending has no link', function (string $state) {
    $invitation = TeamInvitation::factory()->{$state}()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin);

    ($this->link)($invitation)->assertNotFound();
})->with(['accepted', 'expired', 'revoked']);
