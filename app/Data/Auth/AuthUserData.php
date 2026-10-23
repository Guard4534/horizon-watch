<?php

namespace App\Data\Auth;

use App\Models\User;
use Spatie\LaravelData\Data;

class AuthUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            name: $user->name,
            email: $user->email,
        );
    }
}
