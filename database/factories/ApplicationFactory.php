<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Application '.fake()->unique()->numerify('####');

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'host' => Str::slug($name).'.example.com',
        ];
    }
}
