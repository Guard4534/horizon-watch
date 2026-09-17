// Aliases, never copies. These used to be hand-written literal unions, which
// meant phase 2 had to edit this file by hand to add 'viewer' — and a
// hand-written union keeps compiling against a role the backend no longer
// has. App.Enums.* is generated from the PHP enums by
// "php artisan typescript:transform", so adding a role in phase 4 breaks the
// exhaustive switches in @/lib/members and the comparisons here at the same
// time.
export type TeamRole = App.Enums.TeamRole;

export type MemberVisibility = App.Enums.MemberVisibility;

export type Team = {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    role?: TeamRole;
    roleLabel?: string;
    isCurrent?: boolean;
};

export type TeamMember = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: TeamRole;
    role_label: string;
};

// No TeamInvitation type: teams/Edit.vue receives a count of the pending
// invitations and nothing else (see TeamController::edit). The Members view
// has the list, typed as App.Data.Teams.InvitationData.

export type TeamPermissions = {
    canUpdateTeam: boolean;
    canDeleteTeam: boolean;
    canUpdateMember: boolean;
    canRemoveMember: boolean;
    canCreateInvitation: boolean;
};

export type RoleOption = {
    value: TeamRole;
    label: string;
};
