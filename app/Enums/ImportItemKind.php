<?php

namespace App\Enums;

enum ImportItemKind: string
{
    case Application = 'application';
    case Environment = 'environment';
    case AlertRule = 'alert_rule';
}
