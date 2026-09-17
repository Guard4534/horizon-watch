<?php

namespace App\Enums;

enum MuteDuration: string
{
    case OneHour = '1h';
    case FourHours = '4h';
    case OneDay = '24h';
    case UntilResolved = 'resolved';

    public function minutes(): ?int
    {
        return match ($this) {
            self::OneHour => 60,
            self::FourHours => 240,
            self::OneDay => 1440,
            self::UntilResolved => null,
        };
    }
}
