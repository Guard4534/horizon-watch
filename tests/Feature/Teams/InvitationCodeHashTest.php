<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_13_160001_hash_codes_on_team_invitations_table.php');
});

test('upgrading hashes the stored codes, so the links already sent keep working', function () {
    $team = Team::factory()->create();
    $inviter = User::factory()->create();

    $this->migration->down();

    DB::table('team_invitations')->insert([
        'code' => 'sent-before-the-upgrade-'.str_repeat('a', 40),
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => 'member',
        'visibility' => 'all',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(3),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->migration->up();

    expect(Schema::hasColumn('team_invitations', 'code'))->toBeFalse()
        ->and(DB::table('team_invitations')->sole()->code_hash)->toBe(hash('sha256', 'sent-before-the-upgrade-'.str_repeat('a', 40)));

    $this->get(route('invitations.show', 'sent-before-the-upgrade-'.str_repeat('a', 40)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.state', 'open')
            ->where('page.email', 'invited@example.com'));
});

test('an invitation is found by its code, never by the stored hash', function () {
    $invitation = TeamInvitation::factory()->expiresIn(7)->create();

    expect($invitation->code_hash)->toBe(hash('sha256', (string) $invitation->plainCode))
        ->and($invitation->code_hash)->not->toBe($invitation->plainCode);

    $this->get(route('invitations.show', (string) $invitation->plainCode))->assertOk();
    $this->get(route('invitations.show', $invitation->code_hash))->assertNotFound();
});
