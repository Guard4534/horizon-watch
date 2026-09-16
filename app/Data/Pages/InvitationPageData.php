<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class InvitationPageData extends Data
{
    public function __construct(
        // Null whenever the invitation isn't actionable by whoever is
        // asking (every state but "open"): the spec requires wrong_account
        // to say nothing more about the organization, and
        // expired/revoked/accepted to say only that, "senza dettagli" —
        // and this is an Inertia prop, so whatever it holds reaches the
        // client regardless of what the page chooses to render.
        public ?string $organizationName,
        public ?string $roleLabel,
        public ?string $visibilityLabel,
        public ?string $email,
        // One of: open, expired, revoked, accepted, wrong_account.
        public string $state,
        public bool $authenticated,
    ) {}
}
