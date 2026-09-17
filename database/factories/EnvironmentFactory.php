<?php

namespace Database\Factories;

use App\Enums\EnvironmentColor;
use App\Models\Application;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge(
            $this->typical('production', EnvironmentColor::Prod),
            ['application_id' => Application::factory()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function typical(string $name, EnvironmentColor $color): array
    {
        $hasBasicAuth = in_array($color, [EnvironmentColor::Prod, EnvironmentColor::Preprod], true);

        return [
            'name' => $name,
            'color' => $color,
            'horizon_url' => 'https://'.Str::slug($name).'.example.com/horizon',
            'basic_auth_user' => $hasBasicAuth ? 'monitor' : null,
            'basic_auth_password' => $hasBasicAuth ? fake()->password() : null,
            'poll_interval_seconds' => 15,
            'muted_until' => null,
        ];
    }

    public function production(): static
    {
        return $this->state(fn () => $this->typical('production', EnvironmentColor::Prod));
    }

    public function preprod(): static
    {
        return $this->state(fn () => $this->typical('preprod', EnvironmentColor::Preprod));
    }

    public function staging(): static
    {
        return $this->state(fn () => $this->typical('staging', EnvironmentColor::Staging));
    }

    public function develop(): static
    {
        return $this->state(fn () => $this->typical('develop', EnvironmentColor::Develop));
    }

    public function demo(): static
    {
        return $this->state(fn () => $this->typical('demo', EnvironmentColor::Demo));
    }

    public function workerBatch(): static
    {
        return $this->state(fn () => $this->typical('worker-batch', EnvironmentColor::Worker));
    }

    public function testing(): static
    {
        return $this->state(fn () => $this->typical('testing', EnvironmentColor::Testing));
    }
}
