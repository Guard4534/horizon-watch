<?php

namespace App\Data\Teams;

use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Data;

class AcceptInvitationData extends Data
{
    public function __construct(
        public string $name,
        public string $password,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
