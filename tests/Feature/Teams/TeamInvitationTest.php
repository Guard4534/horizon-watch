<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

// Accept/decline/expired/revoked/wrong-account scenarios live in
// InvitationFlowTest.php, together with the guest registration path. This
// file covers what admins do from inside the panel: sending, validating and
// revoking invitations, plus the notification content.
beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
});

test('team invitations can be created', function () {
    Notification::fake();

    $response = $this
        ->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::All->value,
    ]);
});

test('team invitations can be created by admins', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $this->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ]);

    $response->assertRedirect();
});

test('existing team members cannot be invited', function () {
    Notification::fake();

    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this
        ->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'member@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $response = $this
        ->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('a revoked invitation can be invited again', function () {
    Notification::fake();

    TeamInvitation::factory()->revoked()->create([
        'team_id' => $this->team->id,
        'email' => 'invited@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $response = $this
        ->actingAs($this->owner)
        ->post(route('members.invitations.store', ['current_team' => $this->team->slug]), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
            'visibility' => MemberVisibility::All->value,
        ]);

    $response->assertRedirect();
    $this->assertDatabaseCount('team_invitations', 2);
});

test('team invitations can be revoked by owners', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'invited_by' => $this->owner->id,
    ]);

    $response = $this
        ->actingAs($this->owner)
        ->delete(route('members.invitations.destroy', ['current_team' => $this->team->slug, 'invitation' => $invitation->code]));

    $response->assertRedirect();

    expect($invitation->fresh()->isRevoked())->toBeTrue();

    // Revoking is not deleting: the row stays so the invitation page can
    // still say "revoked" instead of behaving as if the code never existed.
    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

test('invitation email links to the invitation page and mentions the organization and role', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'role' => TeamRole::Admin,
        'invited_by' => $this->owner->id,
    ]);

    $mail = (new TeamInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('invitations.show', $invitation->code));

    $body = strtolower(implode(' ', $mail->introLines));
    $this->assertStringContainsString(strtolower($this->team->name), $body);
    $this->assertStringContainsString('admin', $body);
});
