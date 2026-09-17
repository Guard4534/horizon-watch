<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Notifications\Alerts\AlertMail;
use App\Notifications\Alerts\AlertNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Traits\Localizable;

final class EmailPreview
{
    use Localizable;

    public function render(Alert $alert, string $locale): string
    {
        return $this->withLocale($locale, fn (): string => (string) (new AlertNotification($alert))
            ->toMail(new AnonymousNotifiable)
            ->render());
    }

    public function subject(Alert $alert, string $locale): string
    {
        return $this->withLocale($locale, fn (): string => AlertMail::subject(AlertMail::severityTag($alert->severity), $alert));
    }
}
