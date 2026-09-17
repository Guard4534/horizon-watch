<?php

namespace App\Enums;

enum HorizonStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Inactive = 'inactive';
}
