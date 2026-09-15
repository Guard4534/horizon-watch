<?php

namespace App\Data\Settings;

use App\Enums\Locale;
use Spatie\LaravelData\Data;

class UpdateLocaleData extends Data
{
    public function __construct(
        public Locale $locale,
    ) {}
}
