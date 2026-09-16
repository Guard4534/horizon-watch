<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class InvitationPageData extends Data
{
    public function __construct(
        public string $organizationName,
        public string $roleLabel,
        public string $visibilityLabel,
        public string $email,
        // One of: open, expired, revoked, accepted, wrong_account.
        public string $state,
        public bool $authenticated,
    ) {}
}
