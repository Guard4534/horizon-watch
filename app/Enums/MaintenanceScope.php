<?php

namespace App\Enums;

enum MaintenanceScope: string
{
    case Organization = 'organization';
    case Application = 'application';
    case Environment = 'environment';
}
