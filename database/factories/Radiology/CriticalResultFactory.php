<?php

namespace Database\Factories\Radiology;

use App\Models\Radiology\CriticalResult;
use App\Models\Radiology\Read;
use Database\Factories\Radiology\Concerns\CreatesRadiologyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CriticalResult> */
class CriticalResultFactory extends Factory
{
    use CreatesRadiologyFixtures;

    protected $model = CriticalResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $identified = now()->subMinutes(10);

        return [
            'critical_result_uuid' => (string) Str::uuid(),
            'rad_read_id' => fn (): int => (int) Read::factory()->create()->rad_read_id,
            'rad_exam_id' => fn (array $attributes): int => (int) Read::query()->findOrFail($attributes['rad_read_id'])->rad_exam_id,
            'source_id' => fn (array $attributes): int => (int) Read::query()->findOrFail($attributes['rad_read_id'])->source_id,
            'source_result_key' => 'critical-'.Str::uuid(),
            'finding_class' => 'critical',
            'policy_state' => 'notified',
            'identified_at' => $identified,
            'notified_at' => $identified->copy()->addMinutes(2),
            'acknowledged_at' => null,
            'escalated_at' => null,
            'closed_at' => null,
            'recipient_role' => 'ordering_clinician',
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes): array => ['policy_state' => 'acknowledged', 'acknowledged_at' => now()]);
    }
}
