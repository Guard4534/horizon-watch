<?php

namespace App\Support;

final class Duration
{
    /**
     * Seconds in a day, an hour, a minute and a second, largest first.
     */
    private const array SIZES = [86400, 3600, 60, 1];

    /**
     * A duration rounded down to at most $parts units, each spaced from its number:
     * 45 s, 1 min 30 s, 1 h 5 min, 2 d 3 h. The TypeScript formatSeconds() mirrors it.
     */
    public static function format(int $seconds, int $parts = 2): string
    {
        $total = max(0, $seconds);
        $index = 0;

        while ($index < count(self::SIZES) - 1 && $total < self::SIZES[$index]) {
            $index++;
        }

        $size = self::SIZES[$index];
        $text = self::unit($size, intdiv($total, $size));
        $next = self::SIZES[$index + 1] ?? null;

        if ($parts < 2 || $next === null) {
            return $text;
        }

        $minor = intdiv($total % $size, $next);

        return $minor > 0 ? $text.' '.self::unit($next, $minor) : $text;
    }

    private static function unit(int $size, int $count): string
    {
        $value = (string) $count;

        return match ($size) {
            86400 => __(':days d', ['days' => $value]),
            3600 => __(':hours h', ['hours' => $value]),
            60 => __(':minutes min', ['minutes' => $value]),
            default => __(':seconds s', ['seconds' => $value]),
        };
    }
}
