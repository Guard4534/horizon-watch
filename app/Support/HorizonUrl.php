<?php

namespace App\Support;

final class HorizonUrl
{
    private const string AUTHORITY = '#^([a-z][a-z0-9+.-]*)://((?:[^/?\#@]*@)?)([^/?\#]*)#i';

    private const string API_SUFFIX = '#^([a-z][a-z0-9+.-]*://[^/?\#]+/[^?\#]*?[^/?\#])/api(?:/(?:stats|workload|masters|monitoring|metrics|jobs|batches)(?:/[^?\#]*)?)?$#i';

    public static function normalize(string $url): string
    {
        $url = (string) preg_replace_callback(
            self::AUTHORITY,
            fn (array $parts): string => strtolower($parts[1]).'://'.$parts[2].strtolower($parts[3]),
            trim($url),
        );

        if (preg_match('/[?#]/', $url) === 1) {
            return $url;
        }

        return rtrim((string) preg_replace(self::API_SUFFIX, '$1', rtrim($url, '/')), '/');
    }

    public static function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
