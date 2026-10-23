<?php

namespace App\Configuration;

final class ConfigurationFormat
{
    public const string FORMAT = 'horizon-watch.config';

    /**
     * The version of the file format this release writes. Raise it only together with a
     * FormatUpgrade from the previous version, given to ConfigurationReader's $upgrades in
     * AppServiceProvider, e.g. `$this->app->when(ConfigurationReader::class)->needs('$upgrades')
     * ->give(fn () => [1 => new UpgradeFromVersion1])`. Version 1 has no upgrade.
     */
    public const int VERSION = 1;

    public const int MAX_BYTES = 1048576;
}
