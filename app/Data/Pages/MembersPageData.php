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
         * @var array<int, InvitationData>
         */
        public array $invitations,
        /**
         * @var array<int, EnvironmentOptionData>
         */
        public array $environments,
        public MembersPermissionsData $permissions,
        /** @var array<int, PermissionMatrixRowData> */
        public array $matrix,
    ) {}
}
