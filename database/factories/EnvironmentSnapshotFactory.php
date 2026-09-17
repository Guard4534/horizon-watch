<?php

namespace Database\Factories;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvironmentSnapshot>
 */
class EnvironmentSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'captured_at' => now(),
            'status' => EnvironmentStatus::Active,
            'error' => null,
            'breaches' => [],
            'pending' => fake()->numberBetween(0, 500),
            'max_wait_seconds' => fake()->numberBetween(0, 30),
            'jobs_per_minute' => fake()->numberBetween(10, 400),
            'failed_in_window' => fake()->numberBetween(0, 100),
            'failed_window_minutes' => 1440,
            'failed_last_hour' => fake()->numberBetween(0, 5),
            'workers' => fake()->numberBetween(2, 24),
            'node_count' => fake()->numberBetween(1, 3),
            'latency_ms' => fake()->numberBetween(20, 250),
        ];
    }

    public function failed(ReadingError $error = ReadingError::Unreachable): static
    {
        return $this->state(fn () => [
            'status' => EnvironmentStatus::Unreachable,
            'error' => $error,
            'breaches' => [AlertRuleMetric::EndpointUnreachable],
            'pending' => 0,
            'max_wait_seconds' => 0,
            'jobs_per_minute' => 0,
            'failed_in_window' => 0,
            'failed_last_hour' => 0,
            'workers' => 0,
            'node_count' => 0,
            'latency_ms' => null,
        ]);
    }

    /**
     * @param  list<AlertRuleMetric>  $breaches
     */
    public function degraded(array $breaches = [AlertRuleMetric::QueuePending]): static
    {
        return $this->state(fn () => [
            'status' => EnvironmentStatus::Degraded,
            'breaches' => $breaches,
        ]);
    }
}
