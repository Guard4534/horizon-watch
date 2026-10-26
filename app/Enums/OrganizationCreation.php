<?php

namespace App\Enums;

enum OrganizationCreation: string
{
    case Admins = 'admins';
    case Everyone = 'everyone';

    public static function configured(): self
    {
        return self::tryFrom(strtolower(trim(config()->string('horizon-watch.organization_creation')))) ?? self::Admins;
    }
}
