<?php

namespace App\Data\Auth;

use App\Enums\Locale;
use App\Models\User;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;

class AuthUserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?Locale $locale,
        #[MapOutputName('email_verified_at')]
        public ?string $emailVerifiedAt,
        #[MapOutputName('two_factor_enabled')]
        public bool $twoFactorEnabled,
        #[MapOutputName('created_at')]
        public ?string $createdAt,
        #[MapOutputName('updated_at')]
        public ?string $updatedAt,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            locale: $user->locale,
            emailVerifiedAt: $user->email_verified_at?->toIso8601String(),
            twoFactorEnabled: $user->two_factor_confirmed_at !== null,
            createdAt: $user->created_at?->toIso8601String(),
            updatedAt: $user->updated_at?->toIso8601String(),
        );
    }
}
