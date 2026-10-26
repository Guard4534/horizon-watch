<?php

use App\Data\Auth\PasswordPolicyData;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

const PASSWORD_POLICY = [
    'rules' => 'minlength: 10; required: lower; required: digit;',
    'description' => 'At least 10 characters, with letters and numbers',
];

test('the policy describes the rule the defaults enforce', function () {
    expect(PasswordPolicyData::current()->toArray())->toBe(PASSWORD_POLICY)
        ->and(Password::default()->toPasswordRulesString())->toBe(PASSWORD_POLICY['rules']);
});

test('the rule enforces what the description says', function (string $password, bool $passes) {
    expect(Validator::make(['password' => $password], ['password' => [Password::default()]])->passes())->toBe($passes);
})->with([
    'ten characters with a letter and a digit' => ['abcdefghi1', true],
    'letters only' => ['abcdefghij', false],
    'digits only' => ['1234567890', false],
    'too short' => ['abc1', false],
]);

test('the description reads in the language of the page', function () {
    App::setLocale('it');

    expect(PasswordPolicyData::current()->description)->toBe('Almeno 10 caratteri, con lettere e numeri');
});

test('the setup page carries the policy', function () {
    $this->get(route('setup.create'))
        ->assertInertia(fn (Assert $page) => $page->where('page.passwordPolicy', PASSWORD_POLICY));
});

test('the invitation page carries the policy', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'visibility' => MemberVisibility::All,
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->get(route('invitations.show', $invitation->plainCode))
        ->assertInertia(fn (Assert $page) => $page->where('page.passwordPolicy', PASSWORD_POLICY));
});

test('the reset password page carries the policy', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->get(route('password.reset', ['token' => 'a-reset-token', 'email' => 'ada@example.com']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/ResetPassword')
            ->where('passwordPolicy', PASSWORD_POLICY));
});

test('the security page carries the policy', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('passwordPolicy', PASSWORD_POLICY)
            ->missing('passwordRules'));
});
