<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null, 'mail.mailers.smtp.url' => null]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $this->invitation = TeamInvitation::factory()->expiresIn(7)->create(['team_id' => $this->team->id, 'invited_by' => $this->admin->id]);

    $this->link = fn (TeamInvitation $invitation, ?Team $team = null) => $this->postJson(route('members.invitations.link', [
        'current_team' => ($team ?? $this->team)->slug,
        'invitation' => $invitation->id,
    ]));
});

test('an admin gets a new link for a pending invitation, built from the public url, and the earlier link stops working', function () {
    config(['app.url' => 'https://watch.example.com']);

    $this->actingAs($this->admin);

    $url = ($this->link)($this->invitation)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('url');

    $code = Str::after($url, 'https://watch.example.com/invitations/');

    expect($url)->toStartWith('https://watch.example.com/invitations/')
        ->and($code)->toHaveLength(64)->not->toBe($this->invitation->plainCode)
        ->and($this->invitation->fresh()->code_hash)->toBe(hash('sha256', $code));

    $this->get(route('invitations.show', $this->invitation->plainCode))->assertNotFound();
    $this->get(route('invitations.show', $code))->assertOk();
});

test('an invitation link is never handed out on a get request', function () {
    $this->actingAs($this->admin)
        ->getJson(route('members.invitations.link', ['current_team' => $this->team->slug, 'invitation' => $this->invitation->id]))
        ->assertMethodNotAllowed();
});

test('the members page never carries the invitation links', function () {
    $this->actingAs($this->admin)
        ->get(route('members.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.invitations', 1)
            ->where('page.invitations.0.id', $this->invitation->id))
        ->assertDontSee($this->invitation->plainCode);
});

test('once mail is set up, only an instance admin can still fetch an invitation link', function () {
    config(['mail.mailers.smtp.host' => 'smtp.example.com']);

    $this->actingAs($this->admin);

    ($this->link)($this->invitation)->assertForbidden();

    expect($this->invitation->fresh()->code_hash)->toBe($this->invitation->code_hash);

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

    expect($this->invitation->fresh()->code_hash)->toBe($this->invitation->code_hash);
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

    expect($invitation->fresh()->code_hash)->toBe($invitation->code_hash);
})->with(['accepted', 'expired', 'revoked']);

test('new links are throttled apart from the other invitation actions', function () {
    Notification::fake();
    config(['horizon-watch.rate_limits.invitations_per_minute' => 1]);

    $this->actingAs($this->admin);

    $this->post(route('members.invitations.resend', ['current_team' => $this->team->slug, 'invitation' => $this->invitation->id]))
        ->assertRedirect();

    ($this->link)($this->invitation)->assertOk();
    ($this->link)($this->invitation)->assertTooManyRequests();
});
