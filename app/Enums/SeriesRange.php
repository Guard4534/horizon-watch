<?php

namespace App\Enums;

enum SeriesRange: string
{
    case ThreeHours = '3h';
    case Day = '24h';
    case Week = '7d';
}
