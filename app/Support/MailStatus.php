<?php

namespace App\Support;

class MailStatus
{
    public static function isConfigured(): bool
    {
        $mailer = config('mail.mailers.'.config()->string('mail.default'));

        if (! is_array($mailer)) {
            return false;
        }

        return match ($mailer['transport'] ?? null) {
            'smtp' => filled($mailer['host'] ?? null) || filled($mailer['url'] ?? null),
            'log', 'array', null => false,
            default => true,
        };
    }
}
