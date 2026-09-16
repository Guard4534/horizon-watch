<?php

namespace App\Enums;

enum MemberVisibility: string
{
    case All = 'all';
    case NonProduction = 'non_production';
    case Manual = 'manual';

    /**
     * Get the display label used in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::All => 'All environments',
            self::NonProduction => 'Everything except production',
            self::Manual => 'Manual selection',
        };
    }
}
