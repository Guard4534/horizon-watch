<?php

namespace App\Data;

/**
 * The flags teams/Edit.vue draws its buttons from, every one of them
 * answered by the Gate (see HasTeams::toTeamPermissions()).
 *
 * "canAddMember" and "canCancelInvitation" used to sit here too. They lost
 * their last reader when the phase 2 Members view took over inviting and
 * revoking and this branch deleted the two modals that read them, and a
 * permission flag nobody draws is a flag nobody notices going wrong: the
 * abilities themselves live on TeamPolicy, which is what the write paths
 * authorize against.
 */
readonly class TeamPermissions
{
    public function __construct(
        public bool $canUpdateTeam,
        public bool $canDeleteTeam,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
    ) {
        //
    }
}
