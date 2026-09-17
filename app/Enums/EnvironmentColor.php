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

    /**
     * Get the display label used in the interface. Not run through __() on
     * purpose, unlike MemberVisibility::label(): the seven names are the
     * environment vocabulary (prod, preprod, staging, develop, demo,
     * worker, testing), which the Italian interface keeps in English
     * exactly as it keeps the environment names themselves.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The seven colors of the fixed palette, for the environment form.
     *
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
