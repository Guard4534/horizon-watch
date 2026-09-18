<?php

use Symfony\Component\Finder\Finder;

test('the interface promises nothing for a later release', function () {
    $found = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->exclude(['generated']) as $file) {
        foreach (['available soon', 'next release', 'coming soon'] as $phrase) {
            if (str_contains(strtolower($file->getContents()), $phrase)) {
                $found[] = $file->getRelativePathname().': '.$phrase;
            }
        }
    }

    expect($found)->toBe([]);
});

test('no italian line is kept for a later release', function () {
    $italian = json_decode(file_get_contents(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);

    $promises = array_filter(
        array_keys($italian),
        fn (string $key) => str_contains(strtolower($key), 'next release')
            || str_contains(strtolower($key), 'available soon')
            || str_contains(strtolower($key), 'coming soon'),
    );

    expect(array_values($promises))->toBe([]);
});
