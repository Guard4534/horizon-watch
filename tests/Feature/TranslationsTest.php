<?php

use Symfony\Component\Finder\Finder;

/**
 * Matches both quote styles so a literal like __("It's fine") isn't missed just
 * because it uses double quotes. A backreference (\1) ties the closing quote to
 * the opening one instead of duplicating the alternative for each quote style.
 *
 * $excludeDoubleQuotedInterpolation only makes sense for PHP: it only interpolates
 * variables inside double-quoted strings, never in single-quoted PHP or in any JS
 * string, so callers for those must leave it false or a legitimate key such as
 * __('Price: $5 today') or $t('Cost is $10') would be silently skipped.
 *
 * @return array<int, string>
 */
function literalTranslationKeys(string $directory, string $pattern, array $names, bool $excludeDoubleQuotedInterpolation = false): array
{
    $keys = [];

    foreach (Finder::create()->files()->in(base_path($directory))->name($names)->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [, $quote, $raw] = $match;

            // An unescaped "$" in a PHP double-quoted string means variable
            // interpolation, not a static translation key.
            if ($excludeDoubleQuotedInterpolation && $quote === '"' && preg_match('/(?<!\\\\)\$/', $raw) === 1) {
                continue;
            }

            $keys[] = stripcslashes($raw);
        }
    }

    return array_values(array_unique($keys));
}

test('every interface string has an italian translation', function () {
    $italian = json_decode(file_get_contents(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);

    $javascriptCall = <<<'REGEX'
    /(?:\$t|\$tChoice|\btrans|\bwTrans)\(\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1/
    REGEX;

    $phpCall = <<<'REGEX'
    /__\(\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1/
    REGEX;

    $keys = [
        ...literalTranslationKeys('resources/js', $javascriptCall, ['*.vue', '*.ts']),
        ...literalTranslationKeys('app', $phpCall, ['*.php'], excludeDoubleQuotedInterpolation: true),
        // The exception handler in bootstrap/app.php flashes translated toasts
        // too; without this line its strings were unguarded.
        ...literalTranslationKeys('bootstrap', $phpCall, ['*.php'], excludeDoubleQuotedInterpolation: true),
    ];

    $missing = array_values(array_filter($keys, fn (string $key) => ! array_key_exists($key, $italian)));

    expect($missing)->toBe([]);
});

test('the english file stays empty because keys are english', function () {
    expect(json_decode(file_get_contents(lang_path('en.json')), true))->toBe([]);
});
