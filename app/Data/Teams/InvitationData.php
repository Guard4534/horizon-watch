<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

class InvitationData extends Data
{
    public function __construct(
        public int $id,
        public string $email,
        public TeamRole $role,
        public string $roleLabel,
        public MemberVisibility $visibility,
        public string $visibilityLabel,
        public ?string $invitedAt,
        public ?string $expiresAt,
    ) {}
}
