<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

/**
 * A pending invitation as listed in the Members view (email, role, sent,
 * expiry, Resend/Revoke — see the design spec). Built by whichever query
 * needs it; this class only describes the shape.
 */
class InvitationData extends Data
{
    public function __construct(
        // The id, never the code: the code is the invitee's credential and
        // has no business in a page prop or in a URL. Resend and revoke
        // bind "{invitation:id}" for the same reason.
        public int $id,
        public string $email,
        public TeamRole $role,
        public string $roleLabel,
        public MemberVisibility $visibility,
        public string $visibilityLabel,
        public ?string $invitedAt,
        public ?string $expiresAt,
    ) {}
}
