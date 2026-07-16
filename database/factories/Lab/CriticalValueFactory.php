<?php

namespace Database\Factories\Lab;

use App\Models\Lab\CriticalValue;
use App\Models\Lab\Result;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CriticalValue> */
class CriticalValueFactory extends Factory
{
    protected $model = CriticalValue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'critical_value_uuid' => (string) Str::uuid(),
            'lab_result_id' => fn (): int => (int) Result::factory()->critical()->create()->lab_result_id,
            'source_id' => fn (array $attributes): int => (int) Result::query()->findOrFail($attributes['lab_result_id'])->source_id,
            'source_critical_key' => 'critical-'.Str::uuid(),
            'severity' => 'critical',
            'callback_state' => 'pending_notification',
            'identified_at' => now()->subMinutes(10),
            'notified_at' => null,
            'acknowledged_at' => null,
            'escalated_at' => null,
            'closed_at' => null,
            'recipient_role' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function notified(): static
    {
        return $this->state(fn (): array => [
            'callback_state' => 'notified',
            'notified_at' => now()->subMinutes(5),
            'recipient_role' => 'ordering_clinician',
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => [
            'callback_state' => 'acknowledged',
            'notified_at' => now()->subMinutes(5),
            'acknowledged_at' => now()->subMinutes(2),
            'recipient_role' => 'ordering_clinician',
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn (): array => [
            'callback_state' => 'escalated',
            'notified_at' => now()->subMinutes(8),
            'escalated_at' => now()->subMinutes(2),
            'recipient_role' => 'covering_clinician',
        ]);
    }

    public function closed(): static
    {
        return $this->acknowledged()->state(fn (): array => [
            'callback_state' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
