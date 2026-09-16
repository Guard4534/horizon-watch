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
         * Pending invitations. Empty unless the viewer may invite: each one
         * carries the join code, which is a bearer token.
         *
         * @var array<int, InvitationData>
         */
        public array $invitations,
        /**
         * Every environment of the organization, for the manual-visibility
         * picker. Empty unless the viewer may manage members.
         *
         * @var array<int, EnvironmentOptionData>
         */
        public array $environments,
        public MembersPermissionsData $permissions,
        /** @var array<int, PermissionMatrixRowData> */
        public array $matrix,
    ) {}
}
