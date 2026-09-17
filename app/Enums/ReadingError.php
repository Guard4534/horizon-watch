<?php

namespace App\Enums;

enum ReadingError: string
{
    case Unreachable = 'unreachable';
    case Unauthorized = 'unauthorized';
    case NotHorizon = 'not_horizon';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Unreachable => __('Horizon does not answer'),
            self::Unauthorized => __('Horizon refused the credentials'),
            self::NotHorizon => __('The address does not answer like Horizon'),
            self::Blocked => __('This address is not allowed'),
        };
    }
}
