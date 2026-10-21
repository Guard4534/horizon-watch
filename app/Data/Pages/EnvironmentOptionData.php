<?php

namespace App\Data\Pages;

use App\Enums\EnvironmentType;
use App\Models\Environment;
use Spatie\LaravelData\Data;

class EnvironmentOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $applicationName,
        public string $name,
        public EnvironmentType $type,
    ) {}

    public static function of(Environment $environment): self
    {
        return new self(
            id: $environment->id,
            applicationName: $environment->application->name,
            name: $environment->name,
            type: $environment->type,
        );
    }

    public function label(): string
    {
        return $this->applicationName.' / '.$this->name;
    }
}
