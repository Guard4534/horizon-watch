<?php

namespace App\Alerts;

use App\Enums\DeliveryError;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

final class MailFailed extends RuntimeException
{
    private const array AUTHENTICATION_CODES = [530, 534, 535, 538];

    public function __construct(public readonly DeliveryError $reason)
    {
        parent::__construct($reason->value);
    }

    public static function from(Throwable $exception): self
    {
        $code = (int) $exception->getCode();

        return new self(match (true) {
            ! $exception instanceof TransportExceptionInterface => DeliveryError::Mail,
            in_array($code, self::AUTHENTICATION_CODES, true) => DeliveryError::MailAuthentication,
            ! $exception instanceof UnexpectedResponseException => $code === 0 ? DeliveryError::MailUnreachable : DeliveryError::Mail,
            $code >= 500 && $code < 600 => DeliveryError::MailRejected,
            default => DeliveryError::Mail,
        });
    }

    public function permanent(): bool
    {
        return in_array($this->reason, [DeliveryError::MailAuthentication, DeliveryError::MailRejected], true);
    }
}
