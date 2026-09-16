<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentSummaryData;
use App\Data\Pages\EnvironmentFormPageData;
use App\Enums\EnvironmentColor;
use App\Models\Environment;

class EnvironmentEditQuery
{
    public function handle(Environment $environment): EnvironmentFormPageData
    {
        return new EnvironmentFormPageData(
            environment: new EnvironmentSummaryData(
                name: $environment->name,
                color: $environment->color,
                horizonUrl: $environment->horizon_url,
                basicAuthUser: $environment->basic_auth_user,
                pollIntervalSeconds: $environment->poll_interval_seconds,
            ),
            application: ApplicationFormData::from($environment->application),
            colors: EnvironmentColor::options(),
            // Presence check only: never reads the decrypted value.
            hasPassword: $environment->basic_auth_password !== null,
        );
    }
}
