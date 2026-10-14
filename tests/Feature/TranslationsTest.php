<?php

use Symfony\Component\Finder\Finder;

/**
 * @return array<int, string>
 */
function literalTranslationKeys(string $directory, string $pattern, array $names, bool $excludeDoubleQuotedInterpolation = false): array
{
    $keys = [];

    foreach (Finder::create()->files()->in(base_path($directory))->name($names)->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [, $quote, $raw] = $match;

            if ($raw === '' || ($excludeDoubleQuotedInterpolation && $quote === '"' && preg_match('/(?<!\\\\)\$/', $raw) === 1)) {
                continue;
            }

            $keys[] = stripcslashes($raw);
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @return array<string, string>
 */
function italianTranslations(): array
{
    return json_decode(file_get_contents(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return array<int, string>
 */
function frameworkTranslationKeys(): array
{
    return [
        'All rights reserved.',
        'Hello!',
        'If you did not request a password reset, no further action is required.',
        "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:",
        'Regards,',
        'Reset Password',
        'Reset your password',
        'The provided password was incorrect.',
        'The provided two factor authentication code was invalid.',
        'The provided two factor recovery code was invalid.',
        'This password reset link will expire in :count minutes.',
        'You are receiving this email because we received a password reset request for your account.',
    ];
}

test('every interface string has an italian translation', function () {
    $italian = italianTranslations();

    $javascriptCall = <<<'REGEX'
    /(?:\$t|\$tChoice|\btrans|\btransChoice|\bwTrans)\(\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1/
    REGEX;

    $layoutProperty = <<<'REGEX'
    /\b(?:title|kicker|subtitle|description|buttonText)\s*:\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1/
    REGEX;

    $phpCall = <<<'REGEX'
    /(?:__|\btrans_choice|@lang)\(\s*(['"])((?:(?!\1)[^\\]|\\.)*)\1/
    REGEX;

    $keys = [
        ...literalTranslationKeys('resources/js', $javascriptCall, ['*.vue', '*.ts']),
        ...literalTranslationKeys('resources/js', $layoutProperty, ['*.vue', '*.ts']),
        ...literalTranslationKeys('resources/views', $phpCall, ['*.php'], excludeDoubleQuotedInterpolation: true),
        ...literalTranslationKeys('app', $phpCall, ['*.php'], excludeDoubleQuotedInterpolation: true),
        ...literalTranslationKeys('bootstrap', $phpCall, ['*.php'], excludeDoubleQuotedInterpolation: true),
    ];

    $missing = array_values(array_filter($keys, fn (string $key) => ! array_key_exists($key, $italian)));

    expect($missing)->toBe([]);
});

test('the english file stays empty because keys are english', function () {
    expect(json_decode(file_get_contents(lang_path('en.json')), true))->toBe([]);
});

test('the framework strings that reach people have an italian translation', function () {
    expect(array_values(array_diff(frameworkTranslationKeys(), array_keys(italianTranslations()))))->toBe([]);
});

test('every italian translation is still used', function () {
    $source = '';

    foreach (Finder::create()->files()->in(array_map(base_path(...), ['app', 'bootstrap', 'resources/js', 'resources/views']))->name(['*.php', '*.vue', '*.ts'])->exclude(['generated', 'routes', 'actions', 'wayfinder']) as $file) {
        $source .= $file->getContents();
    }

    $unused = array_values(array_filter(
        array_diff(array_keys(italianTranslations()), frameworkTranslationKeys()),
        fn (string $key) => ! str_contains($source, "'".addcslashes($key, "'\\")."'")
            && ! str_contains($source, '"'.addcslashes($key, '"\\').'"'),
    ));

    expect($unused)->toBe([]);
});

test('every italian translation keeps the placeholders and plural forms of its key', function () {
    $placeholders = function (string $text): array {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);

        return collect($matches[1])->unique()->sort()->values()->all();
    };

    $mismatched = collect(italianTranslations())
        ->filter(fn (string $italian, string $key) => $placeholders($italian) !== $placeholders($key)
            || substr_count($italian, '|') !== substr_count($key, '|'))
        ->keys()
        ->all();

    expect($mismatched)->toBe([]);
});
