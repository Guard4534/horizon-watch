<?php

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

class RoleOptionData extends Data
{
    public function __construct(
        public TeamRole $value,
        public string $label,
    ) {}

    public static function of(TeamRole $role): self
    {
        return new self($role, $role->label());
    }
}
