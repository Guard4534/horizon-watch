<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Slug is left out on purpose: the model generates it, unique per team.
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->company(),
            'host' => fake()->domainName(),
        ];
    }
}
