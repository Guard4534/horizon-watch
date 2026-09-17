<?php

namespace App\Data\Teams;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class InviteMemberData extends Data
{
    public function __construct(
        public string $email,
        public TeamRole $role,
        public MemberVisibility $visibility,
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
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($team)],
            'role' => ['required', Rule::enum(TeamRole::class)->except(TeamRole::Owner)],
            'visibility' => ['required', Rule::enum(MemberVisibility::class)],
            'environmentIds' => ['array', 'required_if:visibility,'.MemberVisibility::Manual->value],
            'environmentIds.*' => [
                'integer',
                Rule::exists('environments', 'id')->where(
                    fn ($query) => $query->whereIn('application_id', $team->applications()->select('id'))
                ),
            ],
        ];
    }
}
