<?php

namespace App\Alerts;

use App\Models\Alert;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class AlertClaim
{
    /**
     * @param  array<string, mixed>  $values
     * @param  Closure(Builder<Alert>): Builder<Alert>|null  $onlyWhen
     */
    public static function whileOpen(Alert $alert, array $values, ?Closure $onlyWhen = null): void
    {
        if ($alert->resolved_at !== null) {
            throw ValidationException::withMessages(['alert' => __('This alert is already resolved.')]);
        }

        $claim = Alert::query()->whereKey($alert->id)->whereNull('resolved_at');

        ($onlyWhen === null ? $claim : $onlyWhen($claim))->update($values);
    }
}
