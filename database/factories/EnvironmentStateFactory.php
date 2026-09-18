<?php

namespace Database\Factories;

use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use App\Models\Environment;
use App\Models\EnvironmentState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvironmentState>
 */
class EnvironmentStateFactory extends Factory
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
            'status_since' => fn (array $attributes) => $attributes['captured_at'],
            'error' => null,
            'horizon_status' => HorizonStatus::Running,
            'nodes' => [
                ['hostname' => 'worker-1.example.com', 'status' => 'running', 'workers' => 6, 'supervisors' => 2, 'queues' => 3],
            ],
            'queues' => [
                ['name' => 'default', 'supervisor' => 'supervisor-1', 'workers' => 3, 'pending' => 12, 'waitSeconds' => 2, 'runtimeSeconds' => 0.4],
                ['name' => 'emails', 'supervisor' => 'supervisor-1', 'workers' => 2, 'pending' => 0, 'waitSeconds' => 0, 'runtimeSeconds' => 1.2],
                ['name' => 'reports', 'supervisor' => 'supervisor-2', 'workers' => 1, 'pending' => 3, 'waitSeconds' => 8, 'runtimeSeconds' => null],
            ],
            'failed_jobs' => [
                [
                    'job' => 'App\\Jobs\\SendInvoiceEmail',
                    'queue' => 'emails',
                    'exception' => 'RuntimeException: The mail server did not answer',
                    'tries' => 3,
                    'failedAt' => now()->subMinutes(12)->toIso8601String(),
                ],
            ],
            'pending_jobs' => [],
            'latency_ms' => fake()->numberBetween(20, 250),
        ];
    }

    public function failed(ReadingError $error = ReadingError::Unreachable): static
    {
        return $this->state(fn () => [
            'status' => EnvironmentStatus::Unreachable,
            'error' => $error,
            'latency_ms' => null,
        ]);
    }

    public function empty(): static
    {
        return $this->state(fn () => [
            'nodes' => [],
            'queues' => [],
            'failed_jobs' => [],
            'pending_jobs' => [],
        ]);
    }
}
