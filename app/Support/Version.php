<?php

namespace App\Support;

class Version
{
    public static function current(): string
    {
        $version = trim(config()->string('horizon-watch.version'));

        if ($version === '') {
            return 'dev';
        }

        return preg_match('/^v\d/', $version) === 1 ? substr($version, 1) : $version;
    }

    public static function userAgent(): string
    {
        return 'HorizonWatch/'.self::current();
    }
}
