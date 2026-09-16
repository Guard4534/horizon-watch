<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<int, string>
 */
function literalTranslationKeys(string $directory, string $pattern, array $names): array
{
    $keys = [];

    foreach (Finder::create()->files()->in(base_path($directory))->name($names)->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        preg_match_all($pattern, $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $keys[] = stripcslashes($key);
        }
    }

    return array_values(array_unique($keys));
}

test('every interface string has an italian translation', function () {
    $italian = json_decode(file_get_contents(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);

    $javascriptCall = <<<'REGEX'
    /(?:\$t|\$tChoice|\btrans|\bwTrans)\(\s*'((?:[^'\\]|\\.)*)'/
    REGEX;

    $phpCall = <<<'REGEX'
    /__\(\s*'((?:[^'\\]|\\.)*)'/
    REGEX;

    $keys = [
        ...literalTranslationKeys('resources/js', $javascriptCall, ['*.vue', '*.ts']),
        ...literalTranslationKeys('app', $phpCall, ['*.php']),
    ];

    $missing = array_values(array_filter($keys, fn (string $key) => ! array_key_exists($key, $italian)));

    expect($missing)->toBe([]);
});

test('the english file stays empty because keys are english', function () {
    expect(json_decode(file_get_contents(lang_path('en.json')), true))->toBe([]);
});
