<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

/**
 * What the person looking at the Members view may do there. Computed from
 * the policies, never from the role: see MembersQuery.
 */
class MembersPermissionsData extends Data
{
    public function __construct(
        public bool $canInvite,
        public bool $canManageMembers,
    ) {}
}
