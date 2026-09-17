<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * The Members view sends a role, a visibility, or both: the menu changes
 * one thing at a time, while the manual-visibility dialog sends a
 * visibility together with its environments.
 */
class UpdateMemberData extends Data
{
    public function __construct(
        public ?TeamRole $role = null,
        public ?MemberVisibility $visibility = null,
        /** @var array<int, int> */
        public array $environmentIds = [],
    ) {}

    /**
     * The organization comes from the "{current_team}" route segment,
     * already a model by the time this runs (see EnsureTeamMembership).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $team = request()->route('current_team');

        abort_unless($team instanceof Team, 404);

        return [
            // Owner is excluded because handing over the organization is a
            // flow that does not exist yet: TeamPolicy::updateMember
            // refuses to touch the owner from the other side as well.
            'role' => ['nullable', 'required_without:visibility', Rule::enum(TeamRole::class)->except(TeamRole::Owner)],
            'visibility' => ['nullable', 'required_without:role', Rule::enum(MemberVisibility::class)],
            'environmentIds' => ['array', 'required_if:visibility,'.MemberVisibility::Manual->value],
            'environmentIds.*' => [
                'integer',
                Rule::exists('environments', 'id')->where(
                    fn ($query) => $query->whereIn('application_id', $team->applications()->select('id'))
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'environmentIds.required_if' => __('Pick at least one environment for a manual selection.'),
            // Without this the framework prints the raw key, as in "The
            // selected environmentIds.0 is invalid."
            'environmentIds.*.exists' => __('One of the environments you picked is not part of this organization.'),
        ];
    }
}
