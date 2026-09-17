<?php

namespace App\Externals\Webhook;

use SensitiveParameter;

final class Signature
{
    public const string TIMESTAMP_HEADER = 'X-Horizon-Watch-Timestamp';

    public const string SIGNATURE_HEADER = 'X-Horizon-Watch-Signature';

    public static function sign(#[SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(#[SensitiveParameter] string $secret, int $timestamp, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }
}
