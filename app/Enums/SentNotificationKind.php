<?php

namespace App\Enums;

enum SentNotificationKind: string
{
    case CriticalAlert = 'critical_alert';
    case CriticalRepeated = 'critical_repeated';
    case WarningDigest = 'warning_digest';
    case Resolved = 'resolved';
    case Test = 'test';
}
