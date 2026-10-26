<?php

namespace Tests\Support;

use Illuminate\Encryption\Encrypter;

final class AnotherAppKey
{
    public static function encrypt(string $value): string
    {
        $cipher = config()->string('app.cipher');

        return (new Encrypter(Encrypter::generateKey($cipher), $cipher))->encryptString($value);
    }
}
