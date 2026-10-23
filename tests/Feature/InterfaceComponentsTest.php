<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string>
 */
function scriptSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->notName('*.test.ts')->exclude(['generated', 'routes', 'actions', 'wayfinder']) as $file) {
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

test('every alert row takes its tone and icon from the alert, never from the environment status', function () {
    $sources = scriptSources();

    $leaks = collect($sources)
        ->filter(fn (string $source) => preg_match('/\bseverityIcon\b|statusColor\(alert\b|\benvironmentStatus\b/', $source) === 1)
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($leaks)->toBe([]);

    foreach ([
        'components/monitoring/wall/OpenAlertList.vue',
        'components/monitoring/environment/EnvironmentAlerts.vue',
        'components/monitoring/applications/RecentAlerts.vue',
        'components/monitoring/alerts/AlertTable.vue',
        'components/mobile/alerts/AlertCards.vue',
    ] as $path) {
        expect($sources[$path])
            ->toContain(':is="alertIcon(alert)"')
            ->toContain(':style="{ color: alertTone(alert) }"')
            ->not->toContain('statusColor')
            ->not->toContain('severityColor(');
    }

    expect($sources['components/monitoring/applications/RecentAlerts.vue'])
        ->toContain('<AlertMarks :alert="alert"')
        ->not->toMatch('/<AlertMarks\b[^>]*\bv-if=/');
});

test('the delivery tests live only in the alert settings, where non-managers read the delivery policy', function () {
    $sources = scriptSources();

    $senders = collect($sources)
        ->filter(fn (string $source) => preg_match("/\\btest as \\w+[^;]*from '@\\/routes\\/alert-settings'/s", $source) === 1)
        ->keys()
        ->values()
        ->all();

    expect($senders)->toBe(['components/monitoring/rules/NotificationSettings.vue'])
        ->and($sources)->not->toHaveKey('components/monitoring/alerts/DeliveryTest.vue')
        ->and($sources['pages/monitoring/alert-rules/Index.vue'])->toContain('<DeliveryPolicy')
        ->and($sources['components/monitoring/alerts/DeliveryPolicy.vue'])->toContain("\$t('Email is not set up')")
        ->and($sources['components/monitoring/rules/NotificationSettings.vue'])->toContain("\$t('Extra addresses')")
        ->and($sources['components/monitoring/rules/NotificationSettings.vue'])->not->toContain("\$t('Alert emails')");
});

test('the delivery policy hides its targets while the audience warning says nobody is reached', function () {
    expect(scriptSources()['components/monitoring/alerts/DeliveryPolicy.vue'])
        ->toMatch('/<AudienceWarning\s+v-if="summary\.reachesNobody"/')
        ->toMatch('/<div\s+v-if="!summary\.reachesNobody"\s+class="nc-t-xs nc-tone-soft flex flex-col"[^>]*>\s*<div class="flex items-start gap-2">\s*<PhEnvelopeSimple\b/');
});

test('the account screens say log in and log out, and label the fields name and email', function () {
    $sources = scriptSources();

    foreach (['pages/auth/Login.vue', 'pages/auth/Invitation.vue', 'pages/auth/Setup.vue', 'pages/auth/ResetPassword.vue', 'pages/auth/ForgotPassword.vue', 'pages/auth/ConfirmPassword.vue', 'pages/settings/Profile.vue', 'components/TeamInvitationAlert.vue', 'components/settings/LogoutOtherSessions.vue'] as $path) {
        expect($sources[$path])
            ->not->toMatch('/\bsign(ed)? (in|out)\b/i')
            ->not->toContain("\$t('Full name')")
            ->not->toContain("\$t('Email address')")
            ->not->toContain('area of the application');
    }

    $singleAddressPlaceholders = collect($sources)
        ->flatMap(fn (string $source) => preg_match_all('/placeholder="([^",]*@[^",]*)"/', $source, $found) ? $found[1] : [])
        ->unique()
        ->values()
        ->all();

    expect($sources['pages/auth/Login.vue'])->toContain("\$t('Log in')")
        ->and($sources['pages/settings/Profile.vue'])->toContain('placeholder="email@example.com"')
        ->and($singleAddressPlaceholders)->toBe(['email@example.com']);
});

test('leaving an organization is one action with one modal, offered on your own members row', function () {
    $sources = scriptSources();
    $table = $sources['components/monitoring/members/MemberTable.vue'];

    expect($table)->toContain("import LeaveOrganizationModal from '@/components/teams/LeaveOrganizationModal.vue'")
        ->toContain('<LeaveOrganizationModal')
        ->toMatch('/isActionable\(member\) \|\| \(member\.isSelf && !member\.isOwner\)/')
        ->toMatch('/v-if="member\.isSelf"[^>]*>\s*\{\{ \$t\(\'Leave the organization\'\) \}\}/s')
        ->not->toContain('removing?.isSelf')
        ->not->toContain('losingTheLastAdmin')
        ->and($sources['components/teams/LeaveOrganizationModal.vue'])->toContain('v-if="organization?.isLastAdmin"')
        ->and($sources['pages/monitoring/Members.vue'])->not->toContain("'How access works'")
        ->and($sources['pages/monitoring/Members.vue'])->toContain('nc-two-col');
});

test('every new password field shows the one password policy', function () {
    $sources = scriptSources();

    foreach (['pages/auth/Setup.vue', 'pages/auth/Invitation.vue', 'pages/auth/ResetPassword.vue', 'pages/settings/Security.vue'] as $path) {
        expect($sources[$path])->toContain('passwordPolicy.description');
    }
});

test('the organizations page reads the shared memberships instead of a second copy from its controller', function () {
    expect(scriptSources()['pages/teams/Index.vue'])
        ->not->toMatch('/defineProps<\{\s*teams:/')
        ->toContain('page.props.teams');

    expect((string) file_get_contents(app_path('Http/Controllers/Teams/TeamController.php')))
        ->toContain("Inertia::render('teams/Index')")
        ->not->toContain("'teams' => \$request->user()->toUserTeams(");
});

test('the client never ranks environment statuses itself', function () {
    $ranking = collect(scriptSources())
        ->filter(fn (string $source) => str_contains($source, 'worstTone')
            || preg_match('/Record<App\.Enums\.EnvironmentStatus,\s*number>/', $source) === 1)
        ->keys()
        ->values()
        ->all();

    expect($ranking)->toBe([]);
});

test('the wall keeps the worst first order of the server inside each application group', function () {
    $sources = scriptSources();

    foreach (['pages/monitoring/Wall.vue', 'components/monitoring/wall/ApplicationGroup.vue'] as $path) {
        expect($sources[$path])->not->toMatch('/\.(sort|toSorted)\(/');
    }
});
