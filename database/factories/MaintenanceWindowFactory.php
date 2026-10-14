<?php

namespace Database\Factories;

use App\Enums\MaintenanceScope;
use App\Models\Application;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceWindow>
 */
class MaintenanceWindowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'scope' => MaintenanceScope::Organization,
            'application_id' => null,
            'environment_id' => null,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'reason' => null,
            'created_by' => null,
        ];
    }

    public function forApplication(Application $application): static
    {
        return $this->state(fn () => [
            'team_id' => $application->team_id,
            'scope' => MaintenanceScope::Application,
            'application_id' => $application->id,
        ]);
    }

    public function forEnvironment(Environment $environment): static
    {
        return $this->state(fn () => [
            'team_id' => $environment->team_id,
            'scope' => MaintenanceScope::Environment,
            'environment_id' => $environment->id,
        ]);
    }

    public function between(string $startsAt, string $endsAt): static
    {
        return $this->state(fn () => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
