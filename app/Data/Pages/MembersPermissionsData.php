<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class MembersPermissionsData extends Data
{
    public function __construct(
        public bool $canInvite,
        public bool $canManageMembers,
    ) {}
}
