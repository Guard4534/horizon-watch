<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

/**
 * One row of the "Capabilities by role" matrix: a permission and whether
 * each of the four roles has it. Never written by hand — the four flags
 * come from TeamRole::permissions(), so the table cannot drift from the
 * enum. See MembersQuery::matrix().
 */
class PermissionMatrixRowData extends Data
{
    public function __construct(
        public string $permission,
        public string $label,
        public bool $owner,
        public bool $admin,
        public bool $member,
        public bool $viewer,
    ) {}
}
