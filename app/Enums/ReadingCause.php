<?php

namespace App\Enums;

enum ReadingCause: string
{
    case Timeout = 'timeout';
    case ConnectionFailed = 'connection_failed';
    case Tls = 'tls';
    case Dns = 'dns';
    case Redirect = 'redirect';
    case NotFound = 'not_found';
    case HttpStatus = 'http_status';
    case TooLarge = 'too_large';
    case Compressed = 'compressed';
    case NotJson = 'not_json';
    case UnexpectedJson = 'unexpected_json';
    case PollFailed = 'poll_failed';

    private const array TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];

    public static function fromCurlError(?int $errno): ?self
    {
        return match (true) {
            $errno === 28 => self::Timeout,
            $errno === 7 => self::ConnectionFailed,
            $errno === 6 => self::Dns,
            in_array($errno, self::TLS_ERRORS, true) => self::Tls,
            default => null,
        };
    }
}
