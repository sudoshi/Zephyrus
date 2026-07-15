<?php

namespace Database\Factories\Ancillary;

use App\Models\Ancillary\AncillaryOrder;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AncillaryOrder> */
class AncillaryOrderFactory extends Factory
{
    use CreatesAncillaryIntegrationFixtures;

    protected $model = AncillaryOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $orderedAt = now()->subMinutes(fake()->numberBetween(5, 240));

        return [
            'order_uuid' => (string) Str::uuid(),
            'department' => 'rad',
            'work_item_type' => 'imaging_order',
            'source_id' => fn (): int => $this->createIntegrationSourceId('radiology'),
            'source_order_key' => 'factory-order-'.Str::uuid(),
            'encounter_id' => null,
            'encounter_ref' => null,
            'patient_ref' => 'patient-'.Str::uuid(),
            'patient_class' => fake()->randomElement(['emergency', 'inpatient', 'observation']),
            'priority' => fake()->randomElement(['stat', 'urgent', 'routine']),
            'ordered_at' => $orderedAt,
            'terminal_at' => null,
            'current_state' => 'ordered',
            'current_milestone_code' => null,
            'current_milestone_at' => null,
            'unit_id' => null,
            'source_cutoff_at' => $orderedAt->copy()->addMinutes(fake()->numberBetween(1, 5)),
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function radiology(): static
    {
        return $this->state(fn (): array => [
            'department' => 'rad',
            'work_item_type' => 'imaging_order',
        ]);
    }

    public function lab(): static
    {
        return $this->state(fn (): array => [
            'department' => 'lab',
            'work_item_type' => 'lab_order',
        ]);
    }

    public function pathology(): static
    {
        return $this->state(fn (): array => [
            'department' => 'pathology',
            'work_item_type' => 'ap_case',
        ]);
    }

    public function bloodBank(): static
    {
        return $this->state(fn (): array => [
            'department' => 'blood_bank',
            'work_item_type' => 'blood_bank_request',
        ]);
    }

    public function pharmacy(): static
    {
        return $this->state(fn (): array => [
            'department' => 'rx',
            'work_item_type' => 'medication_order',
        ]);
    }

    public function dischargeBlocking(): static
    {
        return $this->state(fn (): array => [
            'priority' => 'discharge',
            'metadata' => ['discharge_blocking' => true],
        ]);
    }

    public function ownedDemo(string $owner = 'ancillary-demo'): static
    {
        return $this->state(fn (): array => ['demo_owner' => $owner]);
    }

    public function terminal(string $state = 'complete'): static
    {
        return $this->state(function (array $attributes) use ($state): array {
            $terminalAt = now();

            return [
                'terminal_at' => $terminalAt,
                'current_state' => $state,
                'source_cutoff_at' => $terminalAt,
            ];
        });
    }
}
