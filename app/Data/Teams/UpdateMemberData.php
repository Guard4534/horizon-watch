<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class UpdateMemberData extends Data
{
    public function __construct(
        public ?TeamRole $role = null,
        public ?MemberVisibility $visibility = null,
        /** @var array<int, int> */
        public array $environmentIds = [],
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $team = request()->route('current_team');

        abort_unless($team instanceof Team, 404);

        return [
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
            'environmentIds.*.exists' => __('One of the environments you picked is not part of this organization.'),
        ];
    }
}
