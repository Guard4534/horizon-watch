<?php

namespace App\Data\Alerts;

use App\Enums\NotificationChannel;
use Spatie\LaravelData\Data;

class TestNotificationInputData extends Data
{
    public function __construct(
        public NotificationChannel $channel,
    ) {}
}
