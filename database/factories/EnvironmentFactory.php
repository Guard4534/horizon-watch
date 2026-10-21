<?php

namespace Database\Factories;

use App\Enums\EnvironmentType;
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
            $this->typical('production', EnvironmentType::Production),
            [
                'application_id' => Application::factory(),
                'type' => fn (array $attributes) => EnvironmentType::suggestFor((string) $attributes['name']) ?? EnvironmentType::Staging,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function typical(string $name, EnvironmentType $type): array
    {
        $hasBasicAuth = in_array($type, [EnvironmentType::Production, EnvironmentType::Preprod], true);

        return [
            'name' => $name,
            'type' => $type,
            'horizon_url' => 'https://'.Str::slug($name).'.example.com/horizon',
            'basic_auth_user' => $hasBasicAuth ? 'monitor' : null,
            'basic_auth_password' => $hasBasicAuth ? fake()->password() : null,
            'poll_interval_seconds' => config()->integer('horizon-watch.readings.poll_interval_seconds.default'),
        ];
    }

    public function production(): static
    {
        return $this->state(fn () => $this->typical('production', EnvironmentType::Production));
    }

    public function preprod(): static
    {
        return $this->state(fn () => $this->typical('preprod', EnvironmentType::Preprod));
    }

    public function staging(): static
    {
        return $this->state(fn () => $this->typical('staging', EnvironmentType::Staging));
    }

    public function develop(): static
    {
        return $this->state(fn () => $this->typical('develop', EnvironmentType::Development));
    }

    public function demo(): static
    {
        return $this->state(fn () => $this->typical('demo', EnvironmentType::Demo));
    }

    public function workerBatch(): static
    {
        return $this->state(fn () => $this->typical('worker-batch', EnvironmentType::Staging));
    }

    public function testing(): static
    {
        return $this->state(fn () => $this->typical('testing', EnvironmentType::Testing));
    }
}
