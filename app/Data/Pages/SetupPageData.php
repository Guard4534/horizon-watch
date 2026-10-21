<?php

namespace App\Data\Pages;

use App\Data\Auth\PasswordPolicyData;
use Spatie\LaravelData\Data;

class SetupPageData extends Data
{
    public function __construct(
        public bool $requiresToken,
        public PasswordPolicyData $passwordPolicy,
    ) {}
}
