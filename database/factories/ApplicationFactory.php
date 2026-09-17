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
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Neither name nor host comes from faker: company() invents real
        // company names and domainName() emits live domains, and nothing in
        // this repository may carry either (the project notes). The counter keeps
        // names distinct, which is what the per-team slug uniqueness of the
        // tests relies on.
        //
        // Slug is left out on purpose: the model generates it, unique per team.
        $name = 'Application '.fake()->unique()->numerify('####');

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'host' => Str::slug($name).'.example.com',
        ];
    }
}
