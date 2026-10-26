<?php

namespace App\Data\Applications;

use App\Models\Application;
use App\Models\Team;
use App\Rules\UniqueApplicationName;
use Spatie\LaravelData\Data;

class ApplicationFormData extends Data
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => [...self::nameRules(), ...self::uniqueNameRules()],
        ];
    }

    /**
     * The rules a configuration import applies to an application name: the import matches
     * applications by name, so it checks uniqueness itself.
     *
     * @return array<int, string>
     */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, UniqueApplicationName>
     */
    private static function uniqueNameRules(): array
    {
        $route = request()->route();
        $team = $route?->parameter('current_team');

        if ($route === null || ! $team instanceof Team) {
            return [];
        }

        $application = $route->parameter('application');

        return [new UniqueApplicationName($team, $application instanceof Application ? $application : null)];
    }
}
