<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use Spatie\LaravelData\Data;

class VisibilityOptionData extends Data
{
    public function __construct(
        public MemberVisibility $value,
        public string $label,
    ) {}

    public static function of(MemberVisibility $visibility): self
    {
        return new self($visibility, $visibility->label());
    }
}
