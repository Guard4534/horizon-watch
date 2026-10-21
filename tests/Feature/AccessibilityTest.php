<?php

use Symfony\Component\Finder\Finder;

function interfaceSource(string $path): string
{
    return file_get_contents(resource_path($path));
}

/**
 * @return array<string, string>
 */
function interfaceFiles(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->notName('*.test.ts')->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

function cssToken(string $name): string
{
    preg_match('/--'.preg_quote($name, '/').':\s*(#[0-9a-f]{6});/i', interfaceSource('css/nocturne.css'), $match);

    return $match[1];
}

function contrastRatio(string $foreground, string $background): float
{
    $luminance = function (string $hex): float {
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    $lighter = max($luminance($foreground), $luminance($background));
    $darker = min($luminance($foreground), $luminance($background));

    return ($lighter + 0.05) / ($darker + 0.05);
}

test('faint and label text reach the AA contrast for small text on the page and on cards', function () {
    $css = interfaceSource('css/nocturne.css');

    expect(contrastRatio(cssToken('nc-text-faint'), cssToken('nc-bg')))->toBeGreaterThanOrEqual(4.5)
        ->and(contrastRatio(cssToken('nc-text-faint'), cssToken('nc-surface')))->toBeGreaterThanOrEqual(4.5)
        ->and(contrastRatio(cssToken('nc-text-faint'), cssToken('nc-neutral-900')))->toBeGreaterThanOrEqual(4.5);

    expect($css)
        ->toMatch('/\.nc-label \{[^}]*color: var\(--nc-text-faint\);/')
        ->toMatch('/\.nc-tone-faint \{\s*color: var\(--nc-text-faint\);/');
});

test('no interface text is set below 10px', function () {
    $tiny = collect(['css/nocturne.css' => interfaceSource('css/nocturne.css'), ...interfaceFiles()])
        ->filter(fn (string $source) => preg_match('/font-size:\s*[0-9](\.\d+)?px|fontSize:\s*\'[0-9]px\'|text-\[[0-9]px\]/', $source) === 1)
        ->keys()
        ->all();

    expect($tiny)->toBe([]);
});

test('continuous animations stop for people who ask for reduced motion', function () {
    $css = interfaceSource('css/nocturne.css');

    expect($css)->toMatch('/@media \(prefers-reduced-motion: reduce\) \{\s*\*,\s*::before,\s*::after \{[^}]*animation-iteration-count: 1 !important;[^}]*transition-duration: 0\.01ms !important;[^}]*scroll-behavior: auto !important;/');

    $smooth = collect(interfaceFiles())
        ->filter(fn (string $source) => str_contains($source, "'smooth'") && ! str_contains($source, 'prefers-reduced-motion'))
        ->keys()
        ->all();

    expect($smooth)->toBe([]);
});

test('the keyboard follows the reading order: no positive tabindex and a reachable password toggle', function () {
    $positive = collect(interfaceFiles())
        ->filter(fn (string $source) => preg_match('/:?tabindex="[1-9]/', $source) === 1)
        ->keys()
        ->all();

    expect($positive)->toBe([])
        ->and(interfaceSource('js/components/PasswordInput.vue'))->not->toContain('tabindex');
});

test('every page title is the h1 of the page', function () {
    expect(interfaceSource('js/components/AppHeaderBar.vue'))->toMatch('/<h1 class="title truncate">/')
        ->and(interfaceSource('js/layouts/AuthLayout.vue'))->toMatch('/<h1\b/');

    $hidden = collect(interfaceFiles())
        ->filter(fn (string $source, string $path) => str_starts_with($path, 'pages/') && str_contains($source, '<h1'))
        ->keys()
        ->all();

    expect($hidden)->toBe([]);
});

test('every field error is announced and linked to the field it belongs to', function () {
    expect(interfaceSource('js/components/InputError.vue'))->toContain('role="alert"')->toContain(':id="field ? errorId(field) : undefined"');

    $unlinked = [];

    foreach (interfaceFiles() as $path => $source) {
        preg_match_all('/<InputError\b[^>]*\/>/s', $source, $matches);

        foreach ($matches[0] as $tag) {
            if (! preg_match('/\s:?field=/', $tag) && ! str_contains($tag, 'errors.account')) {
                $unlinked[] = $path.': '.preg_replace('/\s+/', ' ', $tag);
            }
        }
    }

    expect($unlinked)->toBe([]);
});

test('the organization dialog does not reuse the id of a field on the page behind it', function () {
    expect(interfaceSource('js/components/teams/CreateOrganizationModal.vue'))
        ->not->toContain('id="name"')
        ->toContain(':id="id"');
});

test('the shell has a skip link, named navigation landmarks and a named language switch', function () {
    $layout = interfaceSource('js/layouts/AppLayout.vue');

    expect($layout)->toContain('href="#content"')->toContain('id="content"')->toContain("\$t('Skip to content')");

    preg_match_all('/<nav\b[^>]*>/s', interfaceSource('js/components/AppSidebar.vue'), $navs);

    expect($navs[0])->toHaveCount(2)
        ->each->toMatch('/aria-labelledby="sidebar-(monitoring|organization)"/');

    expect(interfaceSource('js/components/LocaleSwitch.vue'))
        ->toContain('role="radiogroup"')
        ->toContain(":aria-label=\"\$t('Language')\"")
        ->toContain(':lang="option"');
});

test('search fields have a name and the environment chips expose which one is selected', function () {
    $filters = interfaceSource('js/components/monitoring/wall/WallFilters.vue');
    $applications = interfaceSource('js/pages/monitoring/applications/Index.vue');

    expect($filters)
        ->toContain('type="search"')
        ->toContain(":aria-label=\"\$t('Filter by application or environment')\"")
        ->toContain(':aria-pressed="environmentType === \'\'"')
        ->toContain(':aria-pressed="environmentType === chip.type"');

    expect($applications)
        ->toContain('type="search"')
        ->toContain(":aria-label=\"\$t('Search application')\"")
        ->toContain('hostOf(environment.horizonUrl)')
        ->not->toContain('application.host');
});

test('the chart can be read point by point from the keyboard', function () {
    $chart = interfaceSource('js/components/nocturne/TimeSeriesChart.vue');

    expect($chart)
        ->toContain('@keydown="step"')
        ->toContain(':tabindex="values.length ? 0 : undefined"')
        ->toContain('aria-live="polite"')
        ->toContain('<li v-for="(point, index) in points" :key="index">{{ point }}</li>');
});

test('the full alert log of an application page opens filtered on that application', function () {
    expect(interfaceSource('js/components/monitoring/applications/RecentAlerts.vue'))
        ->toContain('query: { application: applicationId }')
        ->and(interfaceSource('js/pages/monitoring/applications/Show.vue'))
        ->toContain(':application-id="page.application.id"');
});

test('the browser tab shows how many alerts are open and follows the live poll', function () {
    expect(interfaceSource('js/app.ts'))
        ->toContain('page.props.openAlertCount')
        ->toContain('`(${open}) ${full}`');

    expect(interfaceSource('js/layouts/AppLayout.vue'))
        ->toContain('() => page.props.openAlertCount')
        ->toContain('head?.forceUpdate()');
});

test('the login form tabs from the password to the submit button before the reset link', function () {
    $login = interfaceSource('js/pages/auth/Login.vue');

    expect(strpos($login, '<PasswordInput'))->toBeLessThan(strpos($login, 'type="submit"'))
        ->and(strpos($login, 'type="submit"'))->toBeLessThan(strpos($login, "\$t('Forgot your password?')"));
});

test('native controls, search fields included, follow the dark nocturne scheme', function () {
    expect(interfaceSource('css/nocturne.css'))->toMatch('/:root \{\s*color-scheme: dark;/');
});
