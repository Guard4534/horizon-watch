<?php

namespace App\Enums;

enum EnvironmentStatus: string
{
    case Active = 'active';
    case Degraded = 'degraded';
    case Paused = 'paused';
    case Inactive = 'inactive';
    case Unreachable = 'unreachable';
    case Stale = 'stale';

    public function severity(): int
    {
        return match ($this) {
            self::Inactive, self::Unreachable => 0,
            self::Paused, self::Stale => 1,
            self::Degraded => 2,
            self::Active => 3,
        };
    }

    /**
     * @param  iterable<self|null>  $statuses
     */
    public static function worstOf(iterable $statuses): ?self
    {
        $worst = null;

        foreach ($statuses as $status) {
            if ($status !== null && ($worst === null || $status->severity() < $worst->severity())) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function isHealthy(): bool
    {
        return $this === self::Active;
    }

    public function isDown(): bool
    {
        return $this === self::Inactive || $this === self::Unreachable;
    }
}
