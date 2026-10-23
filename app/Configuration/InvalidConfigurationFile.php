<?php

namespace App\Configuration;

use RuntimeException;

final class InvalidConfigurationFile extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new self(__('This file is larger than 1 MB.'));
    }

    public static function malformed(): self
    {
        return new self(__('This file is not valid JSON.'));
    }

    public static function wrongFormat(): self
    {
        return new self(__('This is not a Horizon Watch configuration file.'));
    }

    public static function noVersion(): self
    {
        return new self(__('This file does not say which format version it uses.'));
    }

    public static function newer(): self
    {
        return new self(__('This file comes from a newer Horizon Watch: update the panel first.'));
    }

    public static function wrongShape(): self
    {
        return new self(__('This file lists its applications or alert rules in the wrong shape.'));
    }

    public static function unreadable(string $path): self
    {
        return new self(__('Cannot read :path.', ['path' => $path]));
    }
}
