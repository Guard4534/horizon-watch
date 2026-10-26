<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * @param  array<int, self>  $severities
     */
    public static function worstOf(array $severities): ?self
    {
        if (in_array(self::Critical, $severities, true)) {
            return self::Critical;
        }

        return $severities === [] ? null : self::Warning;
    }
}
