<?php

namespace App\Enums;

enum EnvironmentStatus: string
{
    case Active = 'active';
    case Degraded = 'degraded';
    case Paused = 'paused';
    case Inactive = 'inactive';
    case Unreachable = 'unreachable';

    /**
     * Lower is worse: the wall lists the lowest first.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Inactive, self::Unreachable => 0,
            self::Paused => 1,
            self::Degraded => 2,
            self::Active => 3,
        };
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
