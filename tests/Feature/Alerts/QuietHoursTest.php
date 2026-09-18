<?php

use App\Alerts\QuietHours;
use Carbon\CarbonImmutable;

test('a window inside the day holds from its start up to its end', function (string $at, bool $quiet) {
    expect(QuietHours::contains('09:00', '17:30', 'UTC', CarbonImmutable::parse($at, 'UTC')))->toBe($quiet);
})->with([
    'just before' => ['2026-09-17 08:59:59', false],
    'at the start' => ['2026-09-17 09:00:00', true],
    'inside' => ['2026-09-17 12:00:00', true],
    'just before the end' => ['2026-09-17 17:29:59', true],
    'at the end' => ['2026-09-17 17:30:00', false],
]);

test('a window across midnight holds on both sides of it', function (string $at, bool $quiet) {
    expect(QuietHours::contains('23:00:00', '07:00:00', 'UTC', CarbonImmutable::parse($at, 'UTC')))->toBe($quiet);
})->with([
    'before' => ['2026-09-17 22:59:59', false],
    'at the start' => ['2026-09-17 23:00:00', true],
    'midnight' => ['2026-09-18 00:00:00', true],
    'early morning' => ['2026-09-18 06:59:59', true],
    'at the end' => ['2026-09-18 07:00:00', false],
    'midday' => ['2026-09-18 12:00:00', false],
]);

test('the window is read in the organization time zone', function (string $timezone, string $utc, bool $quiet) {
    expect(QuietHours::contains('23:00', '07:00', $timezone, CarbonImmutable::parse($utc, 'UTC')))->toBe($quiet);
})->with([
    'rome in summer time, 23:00 local' => ['Europe/Rome', '2026-07-01 21:00:00', true],
    'rome in summer time, 22:59 local' => ['Europe/Rome', '2026-07-01 20:59:00', false],
    'rome in summer time, 07:00 local' => ['Europe/Rome', '2026-07-02 05:00:00', false],
    'rome in winter time, 23:00 local' => ['Europe/Rome', '2026-01-15 22:00:00', true],
    'rome in winter time, 22:00 local' => ['Europe/Rome', '2026-01-15 21:00:00', false],
    'rome in winter time, 06:59 local' => ['Europe/Rome', '2026-01-16 05:59:00', true],
    'new york, 23:30 local' => ['America/New_York', '2026-09-18 03:30:00', true],
    'new york, 07:30 local' => ['America/New_York', '2026-09-18 11:30:00', false],
    'utc midnight is 20:00 in new york' => ['America/New_York', '2026-09-18 00:00:00', false],
]);

test('no window without both ends or with equal ends', function (?string $from, ?string $to) {
    expect(QuietHours::contains($from, $to, 'UTC', CarbonImmutable::parse('2026-09-17 23:30:00', 'UTC')))->toBeFalse();
})->with([
    'no start' => [null, '07:00'],
    'no end' => ['23:00', null],
    'neither' => [null, null],
    'equal ends' => ['23:00', '23:00:00'],
]);

test('an unknown time zone falls back to the default one', function () {
    $at = CarbonImmutable::parse('2026-07-01 21:30:00', 'UTC');

    expect(QuietHours::contains('23:00', '23:45', 'Mars/Olympus', $at))->toBeTrue()
        ->and(QuietHours::contains('23:00', '23:45', 'UTC', $at))->toBeFalse();
});

test('the window follows the wall clock across the daylight saving changes of Rome', function (string $from, string $to, string $utc, bool $quiet) {
    expect(QuietHours::contains($from, $to, 'Europe/Rome', CarbonImmutable::parse($utc, 'UTC')))->toBe($quiet);
})->with([
    'march, 01:59 local before the jump' => ['01:00', '03:00', '2026-03-29 00:59:00', true],
    'march, 03:00 local right after the jump' => ['01:00', '03:00', '2026-03-29 01:00:00', false],
    'march, a window inside the skipped hour never holds, before' => ['02:00', '02:30', '2026-03-29 00:59:59', false],
    'march, a window inside the skipped hour never holds, after' => ['02:00', '02:30', '2026-03-29 01:00:00', false],
    'march, 23:00 local is 21:00 utc after the jump' => ['23:00', '07:00', '2026-03-29 21:00:00', true],
    'october, first 02:30 local' => ['02:00', '03:00', '2026-10-25 00:30:00', true],
    'october, second 02:30 local' => ['02:00', '03:00', '2026-10-25 01:30:00', true],
    'october, 03:00 local after the repeated hour' => ['02:00', '03:00', '2026-10-25 02:00:00', false],
    'october, 23:00 local is 22:00 utc after the change' => ['23:00', '07:00', '2026-10-25 22:00:00', true],
    'october, 22:59 local' => ['23:00', '07:00', '2026-10-25 21:59:00', false],
]);

test('a window covers every digest tick only when no quarter of an hour is left outside it', function (?string $from, ?string $to, bool $covers) {
    expect(QuietHours::coversEveryTick($from, $to))->toBe($covers);
})->with([
    'all day but a minute' => ['00:00', '23:59', true],
    'all day but the last quarter' => ['00:00', '23:45', false],
    'across midnight leaving five minutes' => ['00:10', '00:05', true],
    'across midnight leaving midnight' => ['00:01', '00:00', false],
    'across midnight leaving 23:45' => ['23:50', '23:40', false],
    'a night' => ['23:00', '07:00', false],
    'no window' => [null, '07:00', false],
    'equal ends' => ['07:00', '07:00', false],
]);
