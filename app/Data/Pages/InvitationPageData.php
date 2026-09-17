<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class InvitationPageData extends Data
{
    /**
     * @param  array<int, string>  $visibleEnvironmentNames
     */
    public function __construct(
        // The code the page was opened with: the accept, decline and
        // register forms post back to it.
        public string $code,
        // Null whenever the invitation isn't actionable by whoever is
        // asking (every state but "open"): the spec requires wrong_account
        // to say nothing more about the organization, and
        // expired/revoked/accepted to say only that, "senza dettagli" —
        // and this is an Inertia prop, so whatever it holds reaches the
        // client regardless of what the page chooses to render.
        // sign_in_required is nulled the same way: the visitor hasn't
        // proved the address is theirs.
        public ?string $organizationName,
        public ?string $roleLabel,
        public ?string $visibilityLabel,
        public ?string $email,
        // Only for an open invitation with "manual" visibility, where the
        // label alone ("Manual selection") tells the invitee nothing.
        // Empty for every other visibility and every other state.
        public array $visibleEnvironmentNames,
        // One of: open, sign_in_required, expired, revoked, accepted,
        // wrong_account.
        public string $state,
        public bool $authenticated,
    ) {}
}
