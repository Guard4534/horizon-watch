<?php

namespace App\Rules;

class WebhookUrl extends OutboundUrl
{
    protected function credentialsMessage(): string
    {
        return __('The webhook address cannot hold credentials: put a token in its path.');
    }

    protected function queryMessage(): string
    {
        return __('The webhook address cannot have a query string or a fragment: put a token in its path.');
    }
}
