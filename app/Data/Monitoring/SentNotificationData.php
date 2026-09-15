<?php

namespace App\Data\Monitoring;

use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use Spatie\LaravelData\Data;

class SentNotificationData extends Data
{
    public function __construct(
        public NotificationChannel $channel,
        public SentNotificationKind $kind,
        public string $subject,
        public int $minutesAgo,
    ) {}
}
