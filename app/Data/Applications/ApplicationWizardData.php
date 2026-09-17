<?php

namespace App\Data\Applications;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ApplicationWizardData extends Data
{
    public function __construct(
        public ApplicationFormData $application,
        /** @var array<int, EnvironmentFormData> */
        #[DataCollectionOf(EnvironmentFormData::class)]
        public array $environments,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'environments' => ['required', 'array', 'min:1'],
            // The application does not exist yet, so EnvironmentFormData's
            // per-application unique rule has nothing to query: within this
            // payload, "distinct" is what stands in for the environments
            // table's unique index on application_id + name. Without it two
            // rows called the same thing pass validation, the wizard shows a
            // happy summary and the insert answers 500. The message lands on
            // environments.N.name, which the wizard's step mapping already
            // routes to step 2.
            'environments.*.name' => ['distinct'],
        ];
    }
}
