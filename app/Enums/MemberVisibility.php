<?php

namespace App\Enums;

enum MemberVisibility: string
{
    case All = 'all';
    case NonProduction = 'non_production';
    case Manual = 'manual';

    /**
     * Get the display label used in the interface. Translated here because
     * this label is rendered as it arrives (the members table, the pending
     * invitations table and the invitation card all print it verbatim), and
     * written as literal __() arms so TranslationsTest can see the keys.
     * resources/js/lib/members.ts says the same three things in trans(),
     * for the menus the front end builds from the enum on its own.
     */
    public function label(): string
    {
        return match ($this) {
            self::All => __('All environments'),
            self::NonProduction => __('Everything except production'),
            self::Manual => __('Manual selection'),
        };
    }
}
