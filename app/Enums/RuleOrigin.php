<?php

namespace App\Enums;

enum RuleOrigin: string
{
    case Organization = 'organization';
    case Override = 'override';
}
