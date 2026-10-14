<?php

namespace App\Support;

class Version
{
    public static function current(): string
    {
        $version = trim(config()->string('horizon-watch.version'));

        return $version === '' ? 'dev' : $version;
    }

    public static function userAgent(): string
    {
        return 'HorizonWatch/'.self::current();
    }
}
