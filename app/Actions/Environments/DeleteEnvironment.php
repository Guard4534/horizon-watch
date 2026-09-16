<?php

namespace App\Actions\Environments;

use App\Data\Applications\ConfirmByNameData;
use App\Models\Environment;
use Illuminate\Validation\ValidationException;

class DeleteEnvironment
{
    /**
     * Delete the environment after checking the typed confirmation matches
     * its name exactly. environment_user rows cascade at the database
     * level, no soft delete.
     */
    public function handle(Environment $environment, ConfirmByNameData $data): void
    {
        if ($data->name !== $environment->name) {
            throw ValidationException::withMessages([
                'name' => __('The environment name does not match.'),
            ]);
        }

        $environment->delete();
    }
}
