<?php

namespace App\Actions\Applications;

use App\Actions\Environments\AddEnvironment;
use App\Data\Applications\ApplicationWizardData;
use App\Models\Application;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class AddApplication
{
    public function __construct(private AddEnvironment $addEnvironment) {}

    public function handle(Team $team, ApplicationWizardData $data): Application
    {
        return DB::transaction(function () use ($team, $data) {
            $application = $team->applications()->create([
                'name' => $data->application->name,
                'host' => $data->application->host,
            ]);

            foreach ($data->environments as $environmentData) {
                $this->addEnvironment->handle($application, $environmentData);
            }

            return $application;
        });
    }
}
