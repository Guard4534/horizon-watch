<?php

namespace App\Data\Applications;

use App\Enums\EnvironmentColor;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class EnvironmentFormData extends Data
{
    public function __construct(
        public string $name,
        public EnvironmentColor $color,
        public string $horizonUrl,
        public ?string $basicAuthUser = null,
        // Write-only: read by AddEnvironment/UpdateEnvironment to fill the
        // encrypted column, never read back out of the database into a Data
        // instance. EnvironmentSummaryData is what the edit page renders,
        // and it has no such property at all — see its docblock.
        public ?string $basicAuthPassword = null,
        public int $pollIntervalSeconds = 15,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'color' => ['required', Rule::enum(EnvironmentColor::class)],
            'horizonUrl' => ['required', 'url:http,https', 'max:255'],
            'basicAuthUser' => ['nullable', 'string', 'max:255'],
            'basicAuthPassword' => ['nullable', 'string'],
            'pollIntervalSeconds' => ['integer', 'between:5,300'],
        ];
    }
}
