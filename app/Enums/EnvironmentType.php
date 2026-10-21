<?php

namespace App\Enums;

enum EnvironmentType: string
{
    case Production = 'production';
    case Preprod = 'preprod';
    case Staging = 'staging';
    case Development = 'development';
    case Testing = 'testing';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Production => __('Production'),
            self::Preprod => __('Pre-production'),
            self::Staging => __('Staging'),
            self::Development => __('Development'),
            self::Testing => __('Testing'),
            self::Demo => __('Demo'),
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }

    public static function suggestFor(string $name): ?self
    {
        $name = strtolower(trim($name));

        if (preg_match('/^pre-production(?:[-._\s]|$)/', $name) === 1) {
            return self::Preprod;
        }

        return match ((string) preg_replace('/[-._\s].*$/s', '', $name)) {
            'production', 'prod', 'prd', 'live' => self::Production,
            'preprod', 'uat' => self::Preprod,
            'staging', 'stage' => self::Staging,
            'develop', 'development', 'dev' => self::Development,
            'test', 'testing', 'qa' => self::Testing,
            'demo' => self::Demo,
            default => null,
        };
    }
}
