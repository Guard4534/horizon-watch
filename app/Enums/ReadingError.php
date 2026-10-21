<?php

namespace App\Enums;

enum ReadingError: string
{
    case Unreachable = 'unreachable';
    case Unauthorized = 'unauthorized';
    case NotHorizon = 'not_horizon';
    case Blocked = 'blocked';
    case PasswordUnreadable = 'password_unreadable';
}
