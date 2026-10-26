<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\MailStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('mail counts as set up only when its transport can deliver', function (array $config, bool $configured) {
    config($config);

    expect(MailStatus::isConfigured())->toBe($configured);
})->with([
    'smtp with a host' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.com', 'mail.mailers.smtp.url' => null], true],
    'smtp with MAIL_HOST unset' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null, 'mail.mailers.smtp.url' => null], false],
    'smtp without a host' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null], false],
    'smtp with only a url' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => 'smtp://user:secret@smtp.example.com:587'], true],
    'an api transport' => [['mail.default' => 'postmark'], true],
    'the log mailer' => [['mail.default' => 'log'], false],
    'the array mailer' => [['mail.default' => 'array'], false],
    'an unknown mailer' => [['mail.default' => 'missing'], false],
]);

test('pages share whether mail is set up', function () {
    $user = User::factory()->create();
    $wall = route('wall', ['current_team' => $user->currentTeam->slug]);

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null]);

    $this->actingAs($user)->get($wall)->assertInertia(fn (Assert $page) => $page->where('mailConfigured', false));

    config(['mail.mailers.smtp.host' => 'smtp.example.com']);

    $this->actingAs($user)->get($wall)->assertInertia(fn (Assert $page) => $page->where('mailConfigured', true));
});

test('guest pages do not share whether mail is set up', function () {
    User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('mailConfigured', null));
});

test('production sends mail only over smtp, whatever MAIL_MAILER says', function (string $environment, string $mailer) {
    config(['mail.default' => 'log']);
    app()->detectEnvironment(fn () => $environment);

    (fn () => $this->configureMail())->call(new AppServiceProvider(app()));

    expect(config('mail.default'))->toBe($mailer);
})->with([
    'production' => ['production', 'smtp'],
    'local' => ['local', 'log'],
]);
