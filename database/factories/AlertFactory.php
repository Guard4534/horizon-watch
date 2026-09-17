<?php

namespace Database\Factories;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $metric = AlertRuleMetric::QueuePending;

        return [
            'environment_id' => Environment::factory(),
            'team_id' => fn (array $attributes) => $this->environment($attributes)->team_id,
            'metric' => $metric,
            'severity' => fn (array $attributes) => AlertRuleMetric::from($this->value($attributes['metric']))->defaultSeverity(),
            'application_name' => fn (array $attributes) => $this->environment($attributes)->application->name,
            'environment_name' => fn (array $attributes) => $this->environment($attributes)->name,
            'environment_color' => fn (array $attributes) => $this->environment($attributes)->color,
            'threshold' => fn (array $attributes) => AlertRuleMetric::from($this->value($attributes['metric']))->defaultThreshold(),
            'unit' => fn (array $attributes) => AlertRuleMetric::from($this->value($attributes['metric']))->unit(),
            'value' => 2500,
            'detail' => ['pending' => 2500],
            'opened_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'resolved_at' => null,
            'muted_until' => null,
            'muted_indefinitely' => false,
            'muted_by' => null,
            'handled_at' => null,
            'handled_by' => null,
            'last_notified_at' => null,
            'notified' => false,
            'digested_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()->subMinute()]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => AlertSeverity::Critical]);
    }

    public function warning(): static
    {
        return $this->state(fn () => ['severity' => AlertSeverity::Warning]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function environment(array $attributes): Environment
    {
        return Environment::query()->with('application')->whereKey($attributes['environment_id'])->firstOrFail();
    }

    private function value(mixed $metric): string
    {
        return $metric instanceof AlertRuleMetric ? $metric->value : (string) $metric;
    }
}
