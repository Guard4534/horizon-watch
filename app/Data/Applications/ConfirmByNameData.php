<?php

namespace App\Data\Applications;

use Spatie\LaravelData\Data;

/**
 * The typed-name confirmation for a destructive delete. Only checks the
 * shape here; the equality check against the resource's actual name needs
 * the resource itself, so it lives in the deleting action, not here.
 */
class ConfirmByNameData extends Data
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
            'name' => ['required', 'string'],
        ];
    }
}
