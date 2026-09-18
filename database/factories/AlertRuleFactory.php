<?php

namespace Database\Factories;

use App\Enums\AlertRuleMetric;
use App\Models\AlertRule;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'scope' => AlertRule::ORGANIZATION,
            'metric' => AlertRuleMetric::QueuePending,
            'threshold' => 5000,
            'severity' => AlertRuleMetric::QueuePending->defaultSeverity(),
            'notify_email' => true,
            'enabled' => true,
        ];
    }

    public function forScope(string $scope): static
    {
        return $this->state(fn () => ['scope' => $scope]);
    }

    public function inheriting(): static
    {
        return $this->state(fn () => [
            'threshold' => null,
            'severity' => null,
            'notify_email' => null,
            'enabled' => null,
        ]);
    }
}
