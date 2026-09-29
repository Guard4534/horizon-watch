<?php

namespace App\Data\Teams;

use App\Concerns\ValidatesTeamEnvironmentIds;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class UpdateMemberData extends Data
{
    use ValidatesTeamEnvironmentIds;

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
        return [
            'role' => ['nullable', 'required_without:visibility', Rule::enum(TeamRole::class)->except(TeamRole::Owner)],
            'visibility' => ['nullable', 'required_without:role', Rule::enum(MemberVisibility::class)],
            ...self::environmentIdRules(self::currentTeam()),
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
