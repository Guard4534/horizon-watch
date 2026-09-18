<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
