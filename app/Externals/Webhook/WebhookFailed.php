<?php

namespace App\Externals\Webhook;

use App\Enums\DeliveryError;
use RuntimeException;

final class WebhookFailed extends RuntimeException
{
    public function __construct(public readonly DeliveryError $reason)
    {
        parent::__construct($reason->value);
    }
}
