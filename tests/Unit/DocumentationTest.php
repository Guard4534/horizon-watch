<?php

function documentationText(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
}

/**
 * The 1.0 notes: under [Unreleased] until the release moves them under [1.0.0].
 */
function unreleasedChangelog(): string
{
    $changelog = documentationText('CHANGELOG.md');

    foreach (['## [Unreleased]', '## [1.0.0]'] as $heading) {
        $start = strpos($changelog, $heading);

        if ($start === false) {
            continue;
        }

        $end = strpos($changelog, "\n## [", $start + 1);
        $section = $end === false ? substr($changelog, $start) : substr($changelog, $start, $end - $start);

        if (trim(substr($section, strlen($heading))) !== '') {
            return $section;
        }
    }

    return '';
}

test('the readme no longer describes what 1.0 removed or renamed', function (string $stale) {
    expect(documentationText('README.md'))->not->toContain($stale);
})->with([
    'the application domain' => ['primary domain'],
    'the wizard Horizon path' => ['Horizon path'],
    'the production checkbox' => ['Tick **Production'],
    'the old maintenance action' => ['Silence for…'],
    'anomalies' => ['anomal'],
    'a v in the user agent' => ['HorizonWatch/v'],
    'a v in the health version' => ['"version": "v'],
    'the production flag' => ['marked as production'],
    'problems on the wall' => ['counts as a problem'],
    'production from names' => ['marked from their names'],
    'the old duration wording' => ['running for 300 s'],
    'the webhook secret' => ['webhook secret'],
    'email recipients' => ['email recipients'],
    'quiet settings' => ['quiet settings'],
    'the poll interval' => ['poll interval'],
    'a release image with its tag' => ['a release image carries its tag'],
    'signing in to the panel' => ['signing in'],
    'everyone signed in' => ['signed in'],
    'signing out on a rename' => ['signs everyone out'],
    'the setup account as an administrator' => ['the administrator'],
    'the old maintenance verb' => ['silence what you are about to touch'],
]);

test('the readme describes the address, the type, the repeat, maintenance and the version of 1.0', function (string $current) {
    expect(documentationText('README.md'))->toContain($current);
})->with([
    'the Horizon URL' => ['**Horizon URL**'],
    'the normalised example' => ['is stored as `https://shop.example.com/horizon`'],
    'the save-time check' => ['_This address points to a network the panel does not call._'],
    'the environment types' => ['Production, Pre-production, Staging, Development, Testing or Demo'],
    'the repeat policy' => ['**first detection only**'],
    'the maintenance action' => ['**Maintenance for…**'],
    'the browser zone of maintenance' => ["your browser's time zone"],
    'the bare user agent' => ['`HorizonWatch/1.0.0`'],
    'the bare health version' => ['"version": "1.0.0",'],
    'the setup account' => ['create the instance admin account'],
]);

test('the security policy no longer uses the old words', function (string $stale) {
    expect(documentationText('SECURITY.md'))->not->toContain($stale);
})->with([
    'the webhook secret' => ['webhook secret'],
    'sign-in' => ['sign-in'],
    'sign in' => ['sign in'],
    'the setup account as an administrator' => ['its administrator exists'],
]);

test('the security policy describes the save-time check and the rotation on a host move', function (string $current) {
    expect(documentationText('SECURITY.md'))->toContain($current);
})->with([
    'the save-time check' => ['When a Horizon URL or a webhook URL is saved'],
    'the rotation on a host move' => ['A move to another host always issues a new'],
    'no previous signature after a move' => ['forgets the old one at once'],
]);

test('the unreleased notes no longer describe what 1.0 removed or renamed', function (string $stale) {
    expect(unreleasedChangelog())->not->toContain($stale);
})->with([
    'the old maintenance action' => ['Silence for…'],
    'the production checkbox' => ['**Production environment**'],
    'the problems filter' => ['**Problems**'],
    'the production flag' => ['marked as production'],
    'problems everywhere' => ['counts as a problem'],
    'colours without types' => ['Environment colours now come'],
    'the pending jobs wall label' => ['calls its pending total **Pending jobs**'],
    'the webhook secret' => ['webhook secret'],
    'the poll interval' => ['poll interval'],
    'signed in' => ['signed in'],
    'signing in' => ['signing in'],
    'sign-in' => ['sign-in'],
    'Sign-in' => ['Sign-in'],
    'signing out on a rename' => ['signs everyone out'],
    'the old two-factor lockout wording' => ['asks for an administrator'],
    'maintenance that silences' => ['Maintenance windows silence'],
]);

test('the unreleased notes carry the 1.0 upgrade notes and the bare version', function (string $current) {
    expect(unreleasedChangelog())->toContain($current);
})->with([
    'the type upgrade note' => ['colour becomes its type'],
    'the domain upgrade note' => ['applications lose their domain field'],
    'the duration wording' => ['Durations read the same everywhere'],
    'the dropped alert columns' => ['`alerts.notified`, `alerts.unit`'],
    'the dropped user column' => ['`users.email_verified_at`'],
    'the renamed alert column' => ['`alerts.environment_type`'],
    'the time zone labels' => ['browser time zone'],
    'the bare user agent' => ['`HorizonWatch/1.0.0`'],
    'the maintenance action' => ['**Maintenance for…**'],
    'degraded needs attention' => ['a degraded environment now needs attention'],
]);

test('contributors find the glossary and the release version rule, and the product record is current', function () {
    expect(documentationText('CONTRIBUTING.md'))
        ->toContain("\n### Glossary\n")
        ->toContain('| Owner, Admin, Member, Viewer ')
        ->toContain('| Password needed ')
        ->toContain('| Password da inserire ')
        ->toContain('builds the image with `VERSION=1.0.0`')
        ->and(documentationText('PRODUCT.md'))
        ->not->toContain('explicit production flag')
        ->not->toContain('windows that silence alerts')
        ->toContain('environment of type Production')
        ->toContain('windows that mute alerts');
});
