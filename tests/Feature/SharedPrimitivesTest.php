<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string>
 */
function primitiveSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->notName('*.test.ts')->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

function primitiveFile(string $path): string
{
    return file_get_contents(resource_path($path));
}

test('the stylesheet carries the shared checkbox, two-column and narrow page primitives', function () {
    expect(primitiveFile('css/nocturne.css'))
        ->toMatch('/\.nc-checkbox \{[^}]*display: inline-flex;/')
        ->toMatch('/\.nc-checkbox \{[^}]*position: relative;/')
        ->toMatch('/\.nc-radio \{[^}]*position: relative;/')
        ->toMatch('/\.nc-switch \{[^}]*position: relative;/')
        ->toMatch('/\.nc-checkbox \.nc-box \{[^}]*border-radius: var\(--nc-radius-sm\);/')
        ->toMatch('/\.nc-checkbox input:checked \+ \.nc-box \{[^}]*background: var\(--nc-accent\);/')
        ->toMatch('/\.nc-checkbox input:focus-visible \+ \.nc-box \{[^}]*outline: 2px solid var\(--nc-accent\);/')
        ->toMatch('/\.nc-two-col \{[^}]*grid-template-columns: var\(--nc-two-col-columns, minmax\(0, 1fr\) 320px\);/')
        ->toMatch('/@media \(max-width: 1023px\) \{\s*\.nc-two-col \{\s*grid-template-columns: minmax\(0, 1fr\);/')
        ->toMatch('/\.nc-page-narrow \{[^}]*max-width: 940px;[^}]*margin-inline: auto;/');
});

test('status is drawn by the lamp and the pill, not by inline dots or ad-hoc pills', function () {
    $dots = [];

    foreach (primitiveSources() as $path => $source) {
        if (str_starts_with($path, 'components/nocturne/')) {
            continue;
        }

        preg_match_all('/<span\b[^>]*>/s', $source, $tags);

        foreach ($tags[0] as $tag) {
            if (preg_match('/rounded-full|class="dot"/', $tag) === 1
                && preg_match('/:style="[^"]*(statusColor\(|statusTone\(|envColor\(|envTypeColor\(|dotStyle\(|--st-)/s', $tag) === 1) {
                $dots[] = $path;
            }
        }
    }

    expect(array_values(array_unique($dots)))->toBe([]);

    expect(primitiveFile('js/components/nocturne/StatusLamp.vue'))
        ->toContain('status: App.Enums.EnvironmentStatus | null;')
        ->toContain('label?: string;')
        ->toContain('decorative?: boolean;');

    expect(primitiveFile('js/components/nocturne/StatusPill.vue'))
        ->toContain('status: App.Enums.EnvironmentStatus | null;')
        ->toContain('label?: string;')
        ->toContain('<StatusLamp');

    expect(primitiveFile('js/pages/monitoring/environments/Show.vue'))
        ->toContain(':label="statusText(environment)"')
        ->not->toContain('padding: 3px 9px');

    expect(primitiveFile('js/components/mobile/environment/EnvironmentMobile.vue'))
        ->toContain('<StatusPill')
        ->toContain(':tone="statusTone(environment)"')
        ->not->toContain('gap-[5px]"');
});

test('an environment is named by its pill, under its application with a slash', function () {
    expect(file_exists(resource_path('js/components/nocturne/EnvSwatch.vue')))->toBeFalse();

    $sources = primitiveSources();

    expect(collect($sources)->filter(fn (string $source) => str_contains($source, 'EnvSwatch'))->keys()->all())->toBe([]);

    $dotted = collect($sources)
        ->filter(fn (string $source) => preg_match('/applicationName\s*\}\}\s*·|applicationName\}\s*·/', $source) === 1
            || str_contains($source, ':application · every environment'))
        ->keys()
        ->all();

    expect($dotted)->toBe([]);

    foreach ([
        'components/monitoring/alerts/AlertTable.vue',
        'components/monitoring/wall/OpenAlertList.vue',
        'components/mobile/alerts/AlertCards.vue',
        'components/monitoring/alerts/EmailPreview.vue',
        'components/monitoring/applications/RecentAlerts.vue',
        'components/monitoring/members/MemberTable.vue',
        'components/monitoring/members/InviteForm.vue',
        'components/monitoring/rules/ScopeList.vue',
        'pages/monitoring/environments/Show.vue',
    ] as $path) {
        expect($sources[$path])->toContain('<EnvPill');
    }

    expect($sources['components/monitoring/rules/MaintenanceWindows.vue'])
        ->toContain('applicationName }} /')
        ->not->toContain("\$t('every environment')");
});

test('one metric card draws every figure tile', function () {
    expect(file_exists(resource_path('js/components/nocturne/KpiCard.vue')))->toBeFalse()
        ->and(file_exists(resource_path('js/components/nocturne/MetricTile.vue')))->toBeFalse();

    $sources = primitiveSources();

    expect(collect($sources)->filter(fn (string $source) => preg_match('/\b(KpiCard|MetricTile)\b/', $source) === 1)->keys()->all())->toBe([]);

    expect($sources['components/nocturne/MetricCard.vue'])->toContain("size?: 'hero' | 'default' | 'compact';");

    foreach ([
        'pages/monitoring/Wall.vue',
        'components/mobile/wall/MobileWall.vue',
        'pages/monitoring/environments/Show.vue',
        'components/mobile/environment/EnvironmentMobile.vue',
        'pages/monitoring/applications/Show.vue',
    ] as $path) {
        expect($sources[$path])->toContain('<MetricCard');
    }

    expect($sources['pages/monitoring/applications/Show.vue'])->not->toMatch('/<div\s+v-for="stat in stats"[^>]*class="nc-card"/s');
});

test('a checkbox is a switch for on and off, or a checkbox for picks, never the radio look', function () {
    $wrong = [];

    foreach (primitiveSources() as $path => $source) {
        $offset = 0;

        while (($at = strpos($source, 'type="checkbox"', $offset)) !== false) {
            $offset = $at + 1;
            $before = substr($source, 0, $at);

            $labelAt = strrpos($before, '<label');
            $label = $labelAt === false ? '' : substr($source, $labelAt, strpos($source, '>', $labelAt) - $labelAt);

            $inputAt = (int) strrpos($before, '<input');
            $input = substr($source, $inputAt, strpos($source, '/>', $at) - $inputAt);

            $switch = str_contains($label, 'nc-switch') && str_contains($input, 'role="switch"');
            $checkbox = str_contains($label, 'nc-checkbox') && ! str_contains($input, 'role="switch"');

            if (! $switch && ! $checkbox) {
                $wrong[] = $path;
            }
        }
    }

    expect(array_values(array_unique($wrong)))->toBe([]);
});

test('pages with an aside share one two-column layout that stacks below 1024 px', function () {
    $sources = primitiveSources();

    foreach ([
        'pages/monitoring/Wall.vue',
        'pages/monitoring/alerts/Index.vue',
        'pages/monitoring/Members.vue',
        'pages/monitoring/environments/Show.vue',
    ] as $path) {
        expect($sources[$path])->toContain('nc-two-col');
    }

    $ownBreakpoints = collect($sources)
        ->filter(fn (string $source, string $path) => str_starts_with($path, 'pages/') && str_contains($source, '(max-width: 1023px)'))
        ->keys()
        ->all();

    expect($ownBreakpoints)->toBe([])
        ->and($sources['pages/monitoring/alert-rules/Index.vue'])->toContain('class="nc-page rules-grid"')
        ->and($sources['pages/monitoring/Members.vue'])->not->toContain('grid-template-columns');
});

test('detail and form pages open with the shared page header inside the shared page frame', function () {
    $sources = primitiveSources();

    expect($sources['components/PageHeader.vue'])
        ->toContain('<h2')
        ->toContain(':aria-label="$t(\'Breadcrumb\')"')
        ->toMatch('/aria-hidden="true"\s*>\/<\/span/');

    foreach ([
        'pages/monitoring/environments/Show.vue',
        'pages/monitoring/applications/Show.vue',
        'pages/monitoring/applications/Create.vue',
        'pages/monitoring/applications/Edit.vue',
        'pages/monitoring/environments/Create.vue',
        'pages/monitoring/environments/Edit.vue',
    ] as $path) {
        expect($sources[$path])
            ->toContain('<PageHeader')
            ->not->toContain('font-size: 26px')
            ->not->toContain('font-size: 24px');
    }

    foreach ([
        'pages/monitoring/applications/Create.vue',
        'pages/monitoring/applications/Edit.vue',
        'pages/monitoring/environments/Create.vue',
        'pages/monitoring/environments/Edit.vue',
    ] as $path) {
        expect($sources[$path])
            ->toContain('nc-page nc-page-narrow')
            ->not->toContain('max-width: 940px')
            ->not->toContain('max-width: 720px');
    }

    $inlinePadding = collect($sources)
        ->filter(fn (string $source, string $path) => str_starts_with($path, 'pages/') && str_contains($source, 'padding: var(--nc-space-6)'))
        ->keys()
        ->all();

    expect($inlinePadding)->toBe([]);
});

test('settings pages do not repeat the title of the header bar', function () {
    expect(primitiveFile('js/layouts/SettingsLayout.vue'))
        ->not->toContain('<Heading')
        ->not->toContain('Manage your profile and account settings')
        ->toContain(":aria-label=\"\$t('Settings')\"");

    expect(primitiveFile('js/pages/teams/Index.vue'))
        ->not->toContain('<Heading')
        ->toContain("'Every application, environment, member and alert rule belongs to one organization.'")
        ->toContain('data-test="teams-new-team-button"');
});

test('the connection check row and its result line share one tone, icon and label', function () {
    foreach (['components/monitoring/applications/ConnectionTest.vue', 'components/monitoring/applications/ConnectionCheckRow.vue'] as $path) {
        expect(primitiveFile("js/{$path}"))
            ->toContain('connectionTone(outcome.value)')
            ->toContain('connectionIcon(outcome.value)')
            ->not->toContain("'var(--st-")
            ->not->toContain('export type ConnectionOutcome');
    }

    expect(primitiveFile('js/components/monitoring/applications/ConnectionCheckRow.vue'))->toContain('connectionState(outcome.value)');
    expect(primitiveFile('js/components/monitoring/applications/ConnectionTest.vue'))->toContain('connectionMessage(outcome.value)');
});
