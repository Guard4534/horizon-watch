<?php

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

class UserTeamData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?TeamRole $role,
        public ?string $roleLabel,
        public bool $isCurrent,
    ) {}
}
