<?php

namespace App\Data\Pages;

use App\Data\Teams\InvitationData;
use App\Data\Teams\MemberData;
use Spatie\LaravelData\Data;

class MembersPageData extends Data
{
    public function __construct(
        /** @var array<int, MemberData> */
        public array $members,
        /**
         * Pending invitations. Empty unless the viewer may invite: who has
         * been invited where is an admin's business, and only an admin has
         * anything to do with the Resend and Revoke buttons. No join code
         * rides along — that is the invitee's own credential.
         *
         * @var array<int, InvitationData>
         */
        public array $invitations,
        /**
         * Every environment of the organization, for the invite form's
         * manual selection and the manual-visibility dialog. Empty unless
         * the viewer may invite *or* may manage members: an environment
         * must not be named to someone it is not visible to, and either
         * permission opens one of the two pickers. The two conditions are
         * one boolean apart today, which is exactly why this says both.
         *
         * @var array<int, EnvironmentOptionData>
         */
        public array $environments,
        public MembersPermissionsData $permissions,
        /** @var array<int, PermissionMatrixRowData> */
        public array $matrix,
    ) {}
}
