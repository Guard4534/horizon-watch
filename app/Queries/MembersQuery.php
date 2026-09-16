<?php

namespace App\Queries;

use App\Data\Pages\EnvironmentOptionData;
use App\Data\Pages\MembersPageData;
use App\Data\Pages\MembersPermissionsData;
use App\Data\Pages\PermissionMatrixRowData;
use App\Data\Teams\InvitationData;
use App\Data\Teams\MemberData;
use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Support\Facades\Gate;

class MembersQuery
{
    public function __construct(private VisibleEnvironments $visible) {}

    public function handle(Team $team, User $viewer): MembersPageData
    {
        $gate = Gate::forUser($viewer);

        $canInvite = $gate->allows('inviteMember', $team);
        $canManageMembers = $gate->allows('updateMember', $team)
            && $gate->allows('removeMember', $team);

        return new MembersPageData(
            members: $this->members($team, $viewer, $canManageMembers),
            // Gated, not merely hidden in the template: an invitation
            // carries its join code, and anyone who can read the page
            // props can read it.
            invitations: $canInvite ? $this->invitations($team) : [],
            // Gated for the same reason as visibleEnvironmentNames below.
            environments: $canManageMembers ? $this->environments($team) : [],
            permissions: new MembersPermissionsData(
                canInvite: $canInvite,
                canManageMembers: $canManageMembers,
            ),
            matrix: $this->matrix(),
        );
    }

    /**
     * The members, owner first, then admins, members, viewers, and by name
     * inside each role.
     *
     * @return array<int, MemberData>
     */
    private function members(Team $team, User $viewer, bool $withEnvironmentNames): array
    {
        return $team->memberships()
            ->with('user')
            ->get()
            ->sortBy([
                fn (Membership $a, Membership $b) => $b->role->level() <=> $a->role->level(),
                fn (Membership $a, Membership $b) => strcasecmp($a->user->name, $b->user->name),
            ])
            ->map(fn (Membership $membership) => new MemberData(
                id: $membership->user_id,
                name: $membership->user->name,
                email: $membership->user->email,
                initials: $this->initials($membership->user->name),
                role: $membership->role,
                // The mockup writes the role names in lower case and they
                // read the same in both languages, so the tag carries the
                // enum value as it is. Only the owner gets a sentence: the
                // spec shows them as "Owner · admin", because they have the
                // admin's permissions plus deleting the organization.
                roleLabel: $membership->role === TeamRole::Owner
                    ? __('Owner · admin')
                    : $membership->role->value,
                visibility: $membership->visibility,
                visibilityLabel: $membership->visibility->label(),
                visibleEnvironmentNames: $withEnvironmentNames
                    ? $this->manualEnvironmentNames($team, $membership)
                    : [],
                lastSeenAt: null,
                isOwner: $membership->role === TeamRole::Owner,
                isSelf: $membership->user_id === $viewer->id,
            ))
            ->values()
            ->all();
    }

    /**
     * The environments a "manual" member may see. Read through
     * VisibleEnvironments so the rule stays in one place; the other
     * visibilities say everything in their label already.
     *
     * @return array<int, string>
     */
    private function manualEnvironmentNames(Team $team, Membership $membership): array
    {
        if ($membership->visibility !== MemberVisibility::Manual) {
            return [];
        }

        return $this->visible->query($team, $membership->user)
            ->get()
            ->map(fn (Environment $environment) => $this->environmentLabel($environment))
            ->values()
            ->all();
    }

    /**
     * @return array<int, InvitationData>
     */
    private function invitations(Team $team): array
    {
        return $team->invitations()
            ->pending()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TeamInvitation $invitation) => new InvitationData(
                id: $invitation->id,
                code: $invitation->code,
                email: $invitation->email,
                role: $invitation->role,
                roleLabel: $invitation->role->value,
                visibility: $invitation->visibility,
                visibilityLabel: $invitation->visibility->label(),
                invitedAt: $invitation->created_at?->toIso8601String(),
                expiresAt: $invitation->expires_at?->toIso8601String(),
            ))
            ->values()
            ->all();
    }

    /**
     * Every environment of the organization, whatever the viewer's own
     * visibility: an admin configures all of them even when they only see
     * some (the spec calls this case rare and asks for it in writing).
     *
     * @return array<int, EnvironmentOptionData>
     */
    private function environments(Team $team): array
    {
        return $team->environments()
            ->with('application')
            ->get()
            ->sortBy(fn (Environment $environment) => $this->environmentLabel($environment))
            ->map(fn (Environment $environment) => new EnvironmentOptionData(
                id: $environment->id,
                name: $this->environmentLabel($environment),
            ))
            ->values()
            ->all();
    }

    /**
     * One row per permission, with the four roles' answers taken from
     * TeamRole::permissions(). The mockup grouped the permissions into
     * eight prose rows; deriving them instead means the table can never
     * disagree with the enum, and the rows the phases 3 and 4 permissions
     * add show up on their own.
     *
     * @return array<int, PermissionMatrixRowData>
     */
    private function matrix(): array
    {
        return array_map(fn (TeamPermission $permission) => new PermissionMatrixRowData(
            permission: $permission->value,
            label: $this->permissionLabel($permission),
            owner: TeamRole::Owner->hasPermission($permission),
            admin: TeamRole::Admin->hasPermission($permission),
            member: TeamRole::Member->hasPermission($permission),
            viewer: TeamRole::Viewer->hasPermission($permission),
        ), TeamPermission::cases());
    }

    /**
     * Kept here rather than on the enum so the strings sit in a literal
     * __() call site, which is what the translation test can see.
     */
    private function permissionLabel(TeamPermission $permission): string
    {
        return match ($permission) {
            TeamPermission::UpdateTeam => __('Rename the organization'),
            TeamPermission::DeleteTeam => __('Delete the organization'),
            TeamPermission::AddMember => __('Add a member'),
            TeamPermission::UpdateMember => __('Change a role or a visibility'),
            TeamPermission::RemoveMember => __('Remove a member'),
            TeamPermission::CreateInvitation => __('Invite someone'),
            TeamPermission::CancelInvitation => __('Revoke an invitation'),
            TeamPermission::ManageApplications => __('Create and edit applications and environments'),
            TeamPermission::ManageCredentials => __('Manage credentials'),
            TeamPermission::ManageAlertRules => __('Edit thresholds and recipients'),
            TeamPermission::MuteAlert => __('Mute an alert'),
            TeamPermission::HandleAnomaly => __('Mark an anomaly handled'),
            TeamPermission::TestConnection => __('Test the connection'),
        };
    }

    private function environmentLabel(Environment $environment): string
    {
        return $environment->application->name.' / '.$environment->name;
    }

    /**
     * First and last initial, like the front end's getInitials().
     */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '';
        }

        $initials = mb_substr($parts[0], 0, 1);

        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
