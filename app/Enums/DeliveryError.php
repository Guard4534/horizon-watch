<?php

namespace App\Enums;

enum DeliveryError: string
{
    case Timeout = 'timeout';
    case Blocked = 'blocked';
    case Redirect = 'http_3xx';
    case ClientError = 'http_4xx';
    case ServerError = 'http_5xx';
    case Unreachable = 'unreachable';
    case Mail = 'mail';
}
