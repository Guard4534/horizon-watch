<?php

namespace App\Data;

readonly class TeamPermissions
{
    public function __construct(
        public bool $canUpdateTeam,
        public bool $canDeleteTeam,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
    ) {}
}
