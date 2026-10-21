<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Applications\EnvironmentSummaryData;
use App\Data\Pages\EnvironmentFormPageData;
use App\Enums\EnvironmentType;
use App\Models\Environment;
use App\Rules\StoredPasswordStaysWithItsAddress;

class EnvironmentEditQuery
{
    public function handle(Environment $environment): EnvironmentFormPageData
    {
        return new EnvironmentFormPageData(
            environment: new EnvironmentSummaryData(
                name: $environment->name,
                type: $environment->type,
                horizonUrl: EnvironmentFormData::withoutUserinfo($environment->horizon_url),
                basicAuthUser: $environment->basic_auth_user,
                pollIntervalSeconds: $environment->poll_interval_seconds,
                pollingEnabled: $environment->polling_enabled,
            ),
            application: ApplicationFormData::from($environment->application),
            types: EnvironmentType::options(),
            hasPassword: StoredPasswordStaysWithItsAddress::hasStoredPassword($environment),
            applicationSlug: $environment->application->slug,
            slug: $environment->slug,
        );
    }
}
