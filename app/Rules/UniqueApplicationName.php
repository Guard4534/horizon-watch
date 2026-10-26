<?php

namespace App\Rules;

use App\Models\Application;
use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * No two applications of an organization share a name, in any case: the configuration import
 * matches applications by name that way.
 */
class UniqueApplicationName implements ValidationRule
{
    public function __construct(
        private Team $team,
        private ?Application $ignore = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $name = Str::lower(trim($value));

        $taken = $this->team->applications()
            ->when($this->ignore, fn ($query, Application $application) => $query->whereKeyNot($application->id))
            ->pluck('name')
            ->contains(fn (string $other) => Str::lower(trim($other)) === $name);

        if ($taken) {
            $fail(__('Another application of this organization already has this name.'));
        }
    }
}
