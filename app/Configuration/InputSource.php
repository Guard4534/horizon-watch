<?php

namespace App\Configuration;

use ErrorException;

final readonly class InputSource
{
    public function __construct(private string $stdin = 'php://stdin') {}

    /**
     * Reads a configuration file, or standard input for "-", stopping one byte past the limit so
     * the reader can refuse an oversized file without holding all of it.
     *
     * @throws InvalidConfigurationFile
     */
    public function read(string $path): string
    {
        if ($path !== '-' && ! is_file($path)) {
            throw InvalidConfigurationFile::unreadable($path);
        }

        try {
            $handle = fopen($path === '-' ? $this->stdin : $path, 'rb');
        } catch (ErrorException) {
            $handle = false;
        }

        if ($handle === false) {
            throw InvalidConfigurationFile::unreadable($path);
        }

        try {
            $contents = stream_get_contents($handle, ConfigurationFormat::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            throw InvalidConfigurationFile::unreadable($path);
        }

        return $contents;
    }
}
