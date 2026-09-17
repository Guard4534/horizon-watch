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
     * The email is never here: it comes from the invitation itself and
     * can't be changed by the guest filling this form.
     *
     * PasswordValidationRules is an instance trait (built for FormRequests
     * and Actions), and this rules() is static like every other Data class
     * in the app, so it calls Password::defaults() directly — the same
     * choice SetupData made for the same reason.
     *
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
