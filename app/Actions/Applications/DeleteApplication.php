<?php

namespace App\Actions\Applications;

use App\Data\Applications\ConfirmByNameData;
use App\Models\Application;
use Illuminate\Validation\ValidationException;

class DeleteApplication
{
    /**
     * Delete the application after checking the typed confirmation matches
     * its name exactly. Environments (and, from phase 3, their snapshots)
     * cascade at the database level (see the applications/environments
     * migrations' cascadeOnDelete()), no soft delete.
     */
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
