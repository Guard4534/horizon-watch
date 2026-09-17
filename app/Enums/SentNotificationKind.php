<?php

namespace App\Enums;

enum SentNotificationKind: string
{
    case CriticalAlert = 'critical_alert';
    case WebhookDelivery = 'webhook_delivery';
    case WarningDigest = 'warning_digest';
    case Resolved = 'resolved';
    case Test = 'test';
}
