<?php

namespace App\Enums;

enum MemberVisibility: string
{
    case All = 'all';
    case NonProduction = 'non_production';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::All => __('All environments'),
            self::NonProduction => __('Everything except production'),
            self::Manual => __('Manual selection'),
        };
    }
}
