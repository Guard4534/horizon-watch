<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string>
 */
function scriptSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->exclude(['generated', 'routes', 'actions', 'wayfinder']) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

test('every vendored ui component is used by another component', function () {
    $sources = scriptSources();

    $unused = collect($sources)
        ->keys()
        ->filter(fn (string $path) => str_starts_with($path, 'components/ui/') && str_ends_with($path, '.vue'))
        ->filter(function (string $path) use ($sources) {
            $file = basename($path);
            $barrel = dirname($path).'/index.ts';

            preg_match('/default as (\w+) \} from ["\']\.\/'.preg_quote($file, '/').'/', $sources[$barrel] ?? '', $alias);

            $names = array_unique([basename($path, '.vue'), $alias[1] ?? basename($path, '.vue')]);

            return collect($sources)
                ->except([$path, $barrel])
                ->filter(fn (string $source) => collect($names)->contains(
                    fn (string $name) => preg_match('/<'.$name.'\b|\b'.$name.'\b[^\n]*from /', $source) === 1,
                ))
                ->isEmpty();
        })
        ->values()
        ->all();

    expect($unused)->toBe([]);
});

test('settings, organizations and two-factor screens are built from the nocturne primitives', function () {
    $screens = [
        'pages/settings/Profile.vue',
        'pages/settings/Security.vue',
        'pages/teams/Index.vue',
        'layouts/SettingsLayout.vue',
        'components/ManageTwoFactor.vue',
        'components/TwoFactorRecoveryCodes.vue',
        'components/TwoFactorSetupModal.vue',
        'components/DeleteUser.vue',
        'components/settings/LogoutOtherSessions.vue',
        'components/teams/CreateOrganizationModal.vue',
        'components/teams/RenameOrganizationModal.vue',
        'components/teams/LeaveOrganizationModal.vue',
    ];

    $sources = scriptSources();

    $starterKit = collect($screens)
        ->filter(fn (string $path) => preg_match("/@\\/components\\/ui\\/(button|input|label|card|badge|separator|alert|avatar)'|text-muted-foreground|bg-muted\\b/", $sources[$path]) === 1)
        ->values()
        ->all();

    expect($starterKit)->toBe([]);
});

test('native form fields are prefilled with value, which a plain element understands', function () {
    $prefilled = collect(scriptSources())
        ->reject(fn (string $source, string $path) => str_starts_with($path, 'components/ui/'))
        ->filter(fn (string $source) => preg_match('/<(input|textarea|select)\b[^>]*\bdefault-value=/s', $source) === 1)
        ->keys()
        ->values()
        ->all();

    expect($prefilled)->toBe([]);
});

test('one avatar component draws the initials of people and organizations', function () {
    $sources = scriptSources();

    $drawers = collect($sources)
        ->filter(fn (string $source) => str_contains($source, 'getInitials('))
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($drawers)->toBe(['components/nocturne/Avatar.vue', 'lib/initials.ts']);

    foreach (['components/NavUser.vue', 'components/UserInfo.vue', 'components/auth/InvitationCard.vue', 'components/monitoring/members/MemberTable.vue', 'pages/monitoring/Me.vue'] as $path) {
        expect($sources[$path])->toContain('<Avatar :name=');
    }
});

test('the user type only carries what the server shares', function () {
    expect(file_get_contents(resource_path('js/types/auth.ts')))->toContain('export type User = App.Data.Auth.AuthUserData;');
});

test('the global type declarations only augment the shared page props', function () {
    $declarations = file_get_contents(resource_path('js/types/global.d.ts'));

    expect($declarations)
        ->toContain("declare module '@inertiajs/core'")
        ->not->toContain("declare module 'vue'")
        ->not->toContain("declare module 'vite/client'")
        ->not->toContain('[key: string]: unknown');
});

test('the stylesheet keeps no starter kit sources or unused theme tokens', function () {
    expect(file_get_contents(resource_path('css/app.css')))
        ->not->toContain('@source')
        ->not->toContain('--sidebar')
        ->not->toContain('--chart-');
});
