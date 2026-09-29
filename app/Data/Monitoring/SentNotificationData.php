<?php

namespace App\Data\Monitoring;

use App\Enums\DeliveryError;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use Spatie\LaravelData\Data;

class SentNotificationData extends Data
{
    public function __construct(
        public NotificationChannel $channel,
        public SentNotificationKind $kind,
        public DeliveryStatus $status,
        public ?DeliveryError $error,
        public string $subject,
        public ?string $target,
        public int $minutesAgo,
    ) {}
}
