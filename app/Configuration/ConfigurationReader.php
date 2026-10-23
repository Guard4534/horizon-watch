<?php

namespace App\Configuration;

use JsonException;
use LogicException;

final readonly class ConfigurationReader
{
    /**
     * @param  array<int, FormatUpgrade>  $upgrades  keyed by the version each one upgrades from
     */
    public function __construct(
        private int $currentVersion = ConfigurationFormat::VERSION,
        private array $upgrades = [],
    ) {}

    /**
     * @throws InvalidConfigurationFile
     */
    public function read(string $contents): ConfigurationDocument
    {
        if (strlen($contents) > ConfigurationFormat::MAX_BYTES) {
            throw InvalidConfigurationFile::tooLarge();
        }

        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidConfigurationFile::malformed();
        }

        if (! is_array($data) || array_is_list($data) || ($data['format'] ?? null) !== ConfigurationFormat::FORMAT) {
            throw InvalidConfigurationFile::wrongFormat();
        }

        $version = $data['version'] ?? null;

        if (! is_int($version) || $version < 1) {
            throw InvalidConfigurationFile::noVersion();
        }

        if ($version > $this->currentVersion) {
            throw InvalidConfigurationFile::newer();
        }

        $sourceVersion = $version;

        while ($version < $this->currentVersion) {
            $upgrade = $this->upgrades[$version]
                ?? throw new LogicException("No upgrade is registered for configuration format version {$version}.");

            $data = $upgrade->upgrade($data);
            $data['version'] = ++$version;
        }

        $data['alert_rules'] ??= [];

        if (! self::isList($data['applications'] ?? null) || ! self::isList($data['alert_rules'])) {
            throw InvalidConfigurationFile::wrongShape();
        }

        return new ConfigurationDocument($data, $sourceVersion);
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
