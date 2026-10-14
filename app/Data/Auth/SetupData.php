<?php

namespace App\Data\Auth;

use App\Rules\SetupToken;
use App\Rules\TeamName;
use App\Support\SetupStatus;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;

class SetupData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $organization,
        public ?string $setupToken = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'organization' => ['required', 'string', 'max:255', new TeamName],
            'setupToken' => SetupStatus::requiresToken() ? ['required', 'string', new SetupToken] : ['nullable', 'string'],
        ];
    }
}
