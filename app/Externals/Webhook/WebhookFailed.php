<?php

namespace App\Externals\Webhook;

use App\Enums\DeliveryError;
use RuntimeException;

final class WebhookFailed extends RuntimeException
{
    private const array RETRIED_CLIENT_ERRORS = [408, 429];

    public function __construct(
        public readonly DeliveryError $reason,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($reason->value);
    }

    public function permanent(): bool
    {
        return match ($this->reason) {
            DeliveryError::Blocked, DeliveryError::Redirect => true,
            DeliveryError::ClientError => ! in_array($this->status, self::RETRIED_CLIENT_ERRORS, true),
            default => false,
        };
    }
}
