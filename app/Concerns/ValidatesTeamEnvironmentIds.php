<?php

namespace App\Concerns;

use App\Enums\MemberVisibility;
use App\Models\Team;
use Closure;

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
            'environmentIds' => [
                'bail',
                'array',
                'max:'.config()->integer('horizon-watch.limits.environment_ids'),
                'required_if:visibility,'.MemberVisibility::Manual->value,
                function (string $attribute, mixed $value, Closure $fail) use ($team) {
                    $ids = array_values(array_unique(array_map(intval(...), array_filter((array) $value, is_numeric(...)))));

                    if ($ids !== [] && $team->environments()->whereIn('environments.id', $ids)->count() < count($ids)) {
                        $fail(__('One of the environments you picked is not part of this organization.'));
                    }
                },
            ],
            'environmentIds.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function environmentIdMessages(): array
    {
        return [
            'environmentIds.required_if' => __('Pick at least one environment for a manual selection.'),
        ];
    }
}
