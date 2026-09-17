<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

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
