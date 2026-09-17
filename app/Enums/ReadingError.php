<?php

namespace App\Enums;

enum ReadingError: string
{
    case Unreachable = 'unreachable';
    case Unauthorized = 'unauthorized';
    case NotHorizon = 'not_horizon';
    case Blocked = 'blocked';

    /**
     * Get the display label used in the interface. Written as literal __()
     * arms so TranslationsTest can see the keys. Never on the path of
     * StatusEvaluator, which must run without the container.
     */
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
