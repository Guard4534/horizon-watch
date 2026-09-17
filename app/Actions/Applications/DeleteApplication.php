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
            $now = CarbonImmutable::now();

            $application->environments()->each(
                fn (Environment $environment) => $this->alerts->resolveAllFor($environment, $now),
            );

            $application->delete();
        });
    }
}
