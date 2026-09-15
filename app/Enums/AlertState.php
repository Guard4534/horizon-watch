<?php

namespace App\Enums;

enum AlertState: string
{
    case Open = 'open';
    case Muted = 'muted';
    case Resolved = 'resolved';
}
