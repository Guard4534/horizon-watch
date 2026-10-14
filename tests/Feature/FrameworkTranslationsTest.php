<?php

use App\Enums\Locale;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\App;
use Laravel\Fortify\Features;

test('the password reset email is entirely in italian', function () {
    App::setLocale('it');

    $html = (string) (new ResetPassword('token'))->toMail(User::factory()->create())->render();

    expect($html)
        ->toContain('Ciao!')
        ->toContain('Ricevi questa email perché è stato chiesto di reimpostare la password del tuo account.')
        ->toContain('Reimposta password')
        ->toContain('Questo link per reimpostare la password scade tra 60 minuti.')
        ->toContain('Se non l’hai chiesto tu, non devi fare nulla.')
        ->toContain('Saluti,')
        ->toContain('Se il pulsante "Reimposta password" non funziona')
        ->toContain('Tutti i diritti riservati.')
        ->not->toContain('Hello!')
        ->not->toContain('Regards,')
        ->not->toContain('trouble clicking')
        ->not->toContain('All rights reserved.');

    expect((new ResetPassword('token'))->toMail(User::factory()->create())->subject)->toBe('Reimposta la tua password');
});

test('the invitation email is entirely in italian', function () {
    App::setLocale('it');

    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $owner->id]);

    $html = (string) (new TeamInvitationNotification($invitation, (string) $invitation->plainCode))->toMail(new AnonymousNotifiable)->render();

    expect($html)
        ->toContain('Ciao!')
        ->toContain('Saluti,')
        ->toContain('Se il pulsante "Entra nell’organizzazione" non funziona')
        ->toContain('Tutti i diritti riservati.')
        ->not->toContain('Hello!')
        ->not->toContain('trouble clicking');
});

test('a wrong password confirmation is reported in italian', function () {
    $this->actingAs(User::factory()->create(['locale' => Locale::It]))
        ->post(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors(['password' => 'La password inserita non è corretta.']);
});

test('a wrong two-factor code is reported in italian', function (string $field, string $message) {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create(['locale' => Locale::It]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->withHeader('Accept-Language', 'it')
        ->post(route('two-factor.login.store'), [$field => 'not-a-valid-code'])
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'authentication code' => ['code', 'Il codice di autenticazione a due fattori non è valido.'],
    'recovery code' => ['recovery_code', 'Il codice di recupero non è valido.'],
]);

test('a missing current password is named in italian', function () {
    $this->actingAs(User::factory()->create(['locale' => Locale::It]))
        ->put(route('user-password.update'), [
            'current_password' => '',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])
        ->assertSessionHasErrors(['current_password' => 'Il campo password attuale è obbligatorio.']);
});
