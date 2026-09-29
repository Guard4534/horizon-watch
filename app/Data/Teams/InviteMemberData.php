<?php

namespace App\Data\Teams;

use App\Concerns\ValidatesTeamEnvironmentIds;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class InviteMemberData extends Data
{
    use ValidatesTeamEnvironmentIds;

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
        $team = self::currentTeam();

        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($team)],
            'role' => ['required', Rule::enum(TeamRole::class)->except(TeamRole::Owner)],
            'visibility' => ['required', Rule::enum(MemberVisibility::class)],
            ...self::environmentIdRules($team),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return self::environmentIdMessages();
    }
}
