<?php

namespace App\Actions\Applications;

use App\Alerts\AlertEngine;
use App\Data\Applications\ConfirmByNameData;
use App\Models\Application;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteApplication
{
    public function __construct(private readonly AlertEngine $alerts) {}

    public function handle(Application $application, ConfirmByNameData $data): void
    {
        if ($data->name !== $application->name) {
            throw ValidationException::withMessages([
                'name' => __('The application name does not match.'),
            ]);
        }

        DB::transaction(function () use ($application) {
            $this->alerts->resolveAllIn(
                Environment::query()->where('application_id', $application->id),
                CarbonImmutable::now(),
            );

            $application->delete();
        });
    }
}
