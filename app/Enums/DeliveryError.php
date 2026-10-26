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
    case MailUnreachable = 'mail_unreachable';
    case MailAuthentication = 'mail_authentication';
    case MailRejected = 'mail_rejected';
    case SecretUnreadable = 'secret_unreadable';
    case NoLongerDue = 'no_longer_due';
    case OpeningNotDelivered = 'opening_not_delivered';
}
