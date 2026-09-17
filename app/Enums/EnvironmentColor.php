<?php

namespace App\Enums;

enum EnvironmentColor: string
{
    case Prod = 'prod';
    case Preprod = 'preprod';
    case Staging = 'staging';
    case Develop = 'develop';
    case Demo = 'demo';
    case Worker = 'worker';
    case Testing = 'testing';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $color) => ['value' => $color->value, 'label' => $color->label()])
            ->values()
            ->toArray();
    }
}
