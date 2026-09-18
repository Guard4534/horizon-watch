<?php

namespace App\Actions\Environments;

use App\Alerts\AlertEngine;
use App\Data\Applications\ConfirmByNameData;
use App\Models\Environment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteEnvironment
{
    public function __construct(private readonly AlertEngine $alerts) {}

    public function handle(Environment $environment, ConfirmByNameData $data): void
    {
        if ($data->name !== $environment->name) {
            throw ValidationException::withMessages([
                'name' => __('The environment name does not match.'),
            ]);
        }

        DB::transaction(function () use ($environment) {
            $this->alerts->resolveAllFor($environment, CarbonImmutable::now());

            $environment->delete();
        });
    }
}
