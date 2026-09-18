<?php

namespace App\Alerts;

use App\Models\NotificationSetting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

final class QuietHours
{
    public const int DIGEST_TICK_SECONDS = 900;

    private const int DAY_SECONDS = 86400;

    public static function contains(?string $from, ?string $to, string $timezone, CarbonImmutable $at): bool
    {
        $start = self::seconds($from);
        $end = self::seconds($to);

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $local = $at->setTimezone(self::zone($timezone));

        return self::inside($start, $end, $local->hour * 3600 + $local->minute * 60 + $local->second);
    }

    public static function forSetting(?NotificationSetting $setting, CarbonImmutable $at): bool
    {
        return $setting !== null
            && self::contains($setting->quiet_from, $setting->quiet_to, $setting->timezone, $at);
    }

    public static function holdsDigest(?NotificationSetting $setting, CarbonImmutable $at): bool
    {
        return $setting !== null
            && self::forSetting($setting, $at)
            && ! self::coversEveryTick($setting->quiet_from, $setting->quiet_to);
    }

    public static function coversEveryTick(?string $from, ?string $to): bool
    {
        $start = self::seconds($from);
        $end = self::seconds($to);

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        for ($tick = 0; $tick < self::DAY_SECONDS; $tick += self::DIGEST_TICK_SECONDS) {
            if (! self::inside($start, $end, $tick)) {
                return false;
            }
        }

        return true;
    }

    private static function inside(int $start, int $end, int $now): bool
    {
        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    private static function seconds(?string $time): ?int
    {
        if ($time === null || preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $time, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? 0);
    }

    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return new DateTimeZone(NotificationSetting::defaultTimezone());
        }
    }
}
