<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Data;

class UpdateAlertEmailsData extends Data
{
    public function __construct(
        public bool $alertEmails,
    ) {}
}
