<?php

namespace App\Concerns;

use App\Enums\MemberVisibility;
use App\Models\Team;
use Illuminate\Validation\Rule;

trait ValidatesTeamEnvironmentIds
{
    protected static function currentTeam(): Team
    {
        $team = request()->route('current_team');

        abort_unless($team instanceof Team, 404);

        return $team;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected static function environmentIdRules(Team $team): array
    {
        return [
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
    protected static function environmentIdMessages(): array
    {
        return [
            'environmentIds.required_if' => __('Pick at least one environment for a manual selection.'),
            'environmentIds.*.exists' => __('One of the environments you picked is not part of this organization.'),
        ];
    }
}
