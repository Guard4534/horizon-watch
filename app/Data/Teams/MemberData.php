<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Spatie\LaravelData\Data;

/**
 * One row of the Members table: who the person is, what they may do and
 * what they may see. Built by MembersQuery.
 */
class MemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $initials,
        public TeamRole $role,
        public string $roleLabel,
        public MemberVisibility $visibility,
        public string $visibilityLabel,
        /**
         * The ids of the environments a "manual" member has been granted.
         * The dialog that edits the list needs them: it sends the whole
         * list back, so opening it without them would silently drop every
         * grant the admin did not re-tick.
         *
         * @var array<int, int>
         */
        public array $visibleEnvironmentIds,
        /**
         * The same grants as "application / environment" labels, in the
         * same order.
         *
         * Both lists are empty for the other visibilities (the label
         * already says everything) and for a viewer of this page who
         * cannot manage members: the spec forbids naming an environment to
         * someone it is not visible to, and this is an Inertia prop, so
         * whatever it holds reaches the client.
         *
         * @var array<int, string>
         */
        public array $visibleEnvironmentNames,
        // Always null: the starter kit records no "last seen" and adding a
        // column is out of scope for phase 2. The view keeps the mockup's
        // column and renders an em dash. See MembersQuery.
        public ?string $lastSeenAt,
        public bool $isOwner,
        public bool $isSelf,
    ) {}
}
