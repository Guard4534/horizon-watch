<?php

namespace App\Externals\Webhook;

final class WebhookTransfer
{
    public ?int $status = null;

    public bool $tooLarge = false;

    public ?int $errno = null;
}
