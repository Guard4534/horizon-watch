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
        ];
    }
}
