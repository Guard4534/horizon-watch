<?php

use App\Data\Monitoring\QueueData;
use App\Enums\ReadingError;
use Symfony\Component\Finder\Finder;

function stateAndNumbersSource(string $path): string
{
    return file_get_contents(resource_path('js/'.$path));
}

/**
 * @return array<string, string>
 */
function stateAndNumbersSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->notName('*.test.ts')->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

test('the last hour is the toned failed figure on the wall and the window count stays neutral', function (string $path) {
    expect(stateAndNumbersSource($path))
        ->toMatch("/thresholdTone\(\s*environment,\s*'jobs\.failed_per_hour',\s*environment\.failedLastHour,?\s*\)/")
        ->toContain('failedLastHourText(environment)')
        ->toContain('formatCount(environment.failedInWindow)')
        ->not->toMatch('/thresholdTone\([^)]*failedInWindow/')
        ->not->toContain('failedTone');
})->with([
    'wall tile' => 'components/monitoring/wall/EnvironmentTile.vue',
    'mobile wall row' => 'components/mobile/wall/MobileWall.vue',
]);

test('throughput carries its unit on the value on the wall and in the charts', function () {
    expect(stateAndNumbersSource('pages/monitoring/Wall.vue'))
        ->toContain("\$t(':value now', {")
        ->toContain('value: `${page.jobsPerMinute}/min`')
        ->toContain("trans(':range, peak :value', {")
        ->toContain('const describeThroughput = (value: number) => `${value}/min`;')
        ->not->toContain('jobs/min');

    expect(stateAndNumbersSource('components/monitoring/environment/TrendChart.vue'))
        ->toContain("<figcaption class=\"caption\">{{ \$t('Throughput') }}</figcaption>")
        ->toContain("trans(':range, peak :value', {")
        ->toContain('const describe = (value: number) => `${value}/min`;')
        ->not->toContain('jobs/min');

    expect(stateAndNumbersSource('components/mobile/wall/MobileWall.vue'))
        ->not->toMatch('#jobs/min#i');
});

test('every list of applications counts the environments needing attention with one badge', function (string $path) {
    expect(stateAndNumbersSource($path))
        ->toContain("import AttentionBadge from '@/components/monitoring/AttentionBadge.vue';")
        ->toContain('<AttentionBadge')
        ->not->toContain('triageCount')
        ->not->toContain(':count to handle')
        ->not->toContain(':count to triage')
        ->not->toContain("\$t('all good')");
})->with([
    'wall group' => 'components/monitoring/wall/ApplicationGroup.vue',
    'application list' => 'components/monitoring/applications/ApplicationSection.vue',
    'mobile application list' => 'components/mobile/applications/ApplicationList.vue',
]);

test('the application table tones the last hour and keeps the window count as a neutral note', function () {
    expect(stateAndNumbersSource('components/monitoring/applications/EnvironmentComparison.vue'))
        ->toContain("\$t('Failed · last hour')")
        ->toMatch("/thresholdTone\(\s*environment,\s*'jobs\.failed_per_hour',\s*environment\.failedLastHour,?\s*\)/")
        ->toContain('failedLastHourText(environment)')
        ->toContain('formatCount(environment.failedInWindow)')
        ->not->toMatch('/thresholdTone\([^)]*failedInWindow/')
        ->not->toContain('failedTone')
        ->toContain("\$t('Throughput')")
        ->toContain('`${environment.jobsPerMinute}/min`')
        ->not->toContain('jobs/min');
});

test('the queue table shows contributor flags instead of a status that re-applies the rules', function () {
    expect(stateAndNumbersSource('components/monitoring/environment/QueueTable.vue'))
        ->toContain("<th>{{ \$t('Flags') }}</th>")
        ->toContain('<QueueFlags')
        ->not->toContain("\$t('Status')")
        ->not->toContain('queue.status')
        ->not->toContain('waitThreshold');

    expect(property_exists(QueueData::class, 'status'))->toBeFalse();
});

test('a long-running job takes the runtime rule severity, with no 300 s step', function () {
    expect(stateAndNumbersSource('components/monitoring/environment/LongRunningJobs.vue'))
        ->toMatch("/thresholdTone\(\s*\{\s*thresholds,\s*pollingEnabled\s*\},\s*'job\.runtime',\s*seconds\s*\)/")
        ->not->toContain('seconds > 300')
        ->not->toMatch('/var\(--st-(?:ok|warn|down)\)/');
});

test('an inactive Horizon is the banner headline, with the missing master supervisor as its explanation', function () {
    $banner = stateAndNumbersSource('components/monitoring/environment/IncidentBanner.vue');
    $headline = strpos($banner, "\$t('Horizon inactive')");
    $explanation = strpos($banner, "'No active master supervisor: jobs stay pending until one starts again.'");

    expect($headline)->toBeInt()
        ->and($explanation)->toBeInt()
        ->and($headline)->toBeLessThan($explanation)
        ->and($banner)->not->toContain("\$t('No active master supervisor')")
        ->not->toContain('No active workers');
});

test('a reading error has one label helper and no template chain', function () {
    expect(method_exists(ReadingError::class, 'label'))->toBeFalse();

    $labels = [
        'Horizon does not answer',
        'Horizon refused the credentials',
        'The address does not answer like Horizon',
        'This address is not allowed',
        'The stored password cannot be decrypted: enter it again',
    ];
    $helper = 'components/monitoring/environment/readings.ts';

    $chains = array_keys(array_filter(
        stateAndNumbersSources(),
        fn (string $source, string $path) => $path !== $helper && (
            preg_match("/(?:readingError|\.error)\s*===\s*'(?:unreachable|not_horizon|blocked|password_unreadable)'/", $source) === 1
            || array_any($labels, fn (string $label) => str_contains($source, "'{$label}'"))
        ),
        ARRAY_FILTER_USE_BOTH,
    ));
    sort($chains);

    expect($chains)->toBe([]);

    foreach ($labels as $label) {
        expect(stateAndNumbersSource($helper))->toContain("'{$label}'");
    }
});

test('no surface writes throughput as jobs/min', function () {
    $worded = array_keys(array_filter(
        stateAndNumbersSources(),
        fn (string $source) => preg_match('#jobs/min#i', $source) === 1,
    ));
    sort($worded);

    expect($worded)->toBe([]);
});

test('the mobile wall takes its tiles from the shared KPIs and never redefines one', function () {
    expect(stateAndNumbersSource('components/mobile/wall/MobileWall.vue'))
        ->toContain('useWallKpis(() => kpis)')
        ->not->toContain("\$t('Issues')")
        ->not->toContain("\$t('Healthy')")
        ->not->toContain('environmentsActive')
        ->not->toContain('environmentsUp');
});

test('a paused collection shows no incident banner', function () {
    expect(stateAndNumbersSource('composables/useEnvironmentDetail.ts'))
        ->toMatch('/const incident = computed\(\(\) => \{[^}]*needsAttention\(environment\.value\)/');
});

test('both walls word the empty state the same way', function (string $path) {
    expect(stateAndNumbersSource($path))
        ->toContain("'Nothing needs attention. Some environments have no reading yet.'")
        ->not->toContain('first reading');
})->with([
    'desktop' => 'pages/monitoring/Wall.vue',
    'mobile' => 'components/mobile/wall/MobileWall.vue',
]);
