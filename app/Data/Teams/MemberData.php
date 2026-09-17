<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

class MemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $initials,
        public TeamRole $role,
        public string $roleLabel,
        public MemberVisibility $visibility,
        public string $visibilityLabel,
        /**
         * @var array<int, int>
         */
        public array $visibleEnvironmentIds,
        /**
         * @var array<int, string>
         */
        public array $visibleEnvironmentNames,
        public ?string $lastSeenAt,
        public bool $isOwner,
        public bool $isSelf,
    ) {}
}
