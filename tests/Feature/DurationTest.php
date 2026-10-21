<?php

use App\Support\Duration;
use Illuminate\Support\Facades\App;

test('a duration keeps at most two units, rounded down, each spaced from its number', function (int $seconds, string $expected) {
    expect(Duration::format($seconds))->toBe($expected);
})->with([
    'nothing' => [0, '0 s'],
    'a negative span' => [-5, '0 s'],
    'seconds' => [45, '45 s'],
    'one minute' => [60, '1 min'],
    'minutes and seconds' => [90, '1 min 30 s'],
    'just under an hour' => [3599, '59 min 59 s'],
    'a whole hour' => [3600, '1 h'],
    'hours and minutes, seconds dropped' => [3959, '1 h 5 min'],
    'just under a day' => [86399, '23 h 59 min'],
    'a day from 24 hours' => [86400, '1 d'],
    'days and hours' => [183600, '2 d 3 h'],
    'days and hours, minutes dropped' => [90061, '1 d 1 h'],
]);

test('a duration can keep only its largest unit', function () {
    expect(Duration::format(90, parts: 1))->toBe('1 min')
        ->and(Duration::format(3959, parts: 1))->toBe('1 h')
        ->and(Duration::format(183600, parts: 1))->toBe('2 d');
});

test('a duration speaks the language of the app', function () {
    App::setLocale('it');

    expect(Duration::format(183600))->toBe('2 g 3 h')
        ->and(Duration::format(95))->toBe('1 min 35 s');
});
