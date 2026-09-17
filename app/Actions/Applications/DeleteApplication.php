<?php

namespace App\Actions\Applications;

use App\Data\Applications\ConfirmByNameData;
use App\Models\Application;
use Illuminate\Validation\ValidationException;

class DeleteApplication
{
    public function handle(Application $application, ConfirmByNameData $data): void
    {
        if ($data->name !== $application->name) {
            throw ValidationException::withMessages([
                'name' => __('The application name does not match.'),
            ]);
        }

        $application->delete();
    }
}
