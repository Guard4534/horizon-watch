<?php

namespace Tests\Support;

use App\Configuration\ConfigurationDocument;
use App\Configuration\ConfigurationReader;

final class ConfigurationFiles
{
    /**
     * @param  array<int, array<string, mixed>>  $applications
     * @param  array<int, array<string, mixed>>  $alertRules
     * @return array<string, mixed>
     */
    public static function document(array $applications = [], array $alertRules = [], int $version = 1): array
    {
        return [
            'format' => 'horizon-watch.config',
            'version' => $version,
            'exported_at' => '2026-10-16T09:00:00Z',
            'app_version' => '1.0.0',
            'organization' => 'Example Organization',
            'applications' => $applications,
            'alert_rules' => $alertRules,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function environment(array $overrides = []): array
    {
        return [
            'name' => 'production',
            'type' => 'production',
            'horizon_url' => 'https://shop.example.com/horizon',
            'basic_auth_user' => null,
            'poll_interval_seconds' => 60,
            'polling_enabled' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function read(array $document): ConfigurationDocument
    {
        return app(ConfigurationReader::class)->read(json_encode($document, JSON_THROW_ON_ERROR));
    }
}
