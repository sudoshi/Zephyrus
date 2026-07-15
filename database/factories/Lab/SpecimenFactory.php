<?php

namespace Database\Factories\Lab;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\Specimen;
use Database\Factories\Lab\Concerns\CreatesLabFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Specimen> */
class SpecimenFactory extends Factory
{
    use CreatesLabFixtures;

    protected $model = Specimen::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $collected = now()->subMinutes(40);
        $received = $collected->copy()->addMinutes(20);

        return [
            'specimen_uuid' => (string) Str::uuid(),
            'ancillary_order_id' => fn (): int => $this->createLabOrderId(),
            'source_id' => fn (array $attributes): int => (int) AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->source_id,
            'source_specimen_key' => 'specimen-'.Str::uuid(),
            'source_accession_key' => 'accession-'.Str::uuid(),
            'parent_specimen_id' => null,
            'encounter_id' => fn (array $attributes): ?int => AncillaryOrder::query()->findOrFail($attributes['ancillary_order_id'])->encounter_id,
            'specimen_type' => 'serum',
            'container_type' => 'serum_separator_tube',
            'collector_role' => 'registered_nurse',
            'collection_method' => 'venipuncture',
            'status' => 'received',
            'rejection_reason_code' => null,
            'collected_at' => $collected,
            'in_transit_at' => $collected->copy()->addMinutes(5),
            'received_at' => $received,
            'rejected_at' => null,
            'recollect_ordered_at' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function pendingCollection(): static
    {
        return $this->state(fn (): array => [
            'status' => 'collection_pending',
            'collected_at' => null,
            'in_transit_at' => null,
            'received_at' => null,
        ]);
    }

    public function inTransit(): static
    {
        return $this->state(function (): array {
            $collected = now()->subMinutes(20);

            return [
                'status' => 'in_transit',
                'collected_at' => $collected,
                'in_transit_at' => $collected->copy()->addMinutes(5),
                'received_at' => null,
            ];
        });
    }

    public function rejected(string $reasonCode = 'hemolysis'): static
    {
        return $this->state(function () use ($reasonCode): array {
            $collected = now()->subMinutes(30);

            return [
                'status' => 'rejected',
                'rejection_reason_code' => $reasonCode,
                'collected_at' => $collected,
                'in_transit_at' => null,
                'received_at' => null,
                'rejected_at' => $collected->copy()->addMinutes(20),
                'recollect_ordered_at' => null,
            ];
        });
    }

    public function recollectOf(Specimen $parent): static
    {
        return $this->pendingCollection()->state(fn (): array => [
            'parent_specimen_id' => $parent->lab_specimen_id,
            'metadata' => [
                'collection_reason' => 'recollect',
                'parent_rejection_reason_code' => $parent->rejection_reason_code,
            ],
        ]);
    }

    public function microbiology(): static
    {
        return $this->state(fn (): array => [
            'specimen_type' => 'blood_culture_set',
            'container_type' => 'aerobic_anaerobic_bottles',
            'collection_method' => 'blood_culture_collection',
        ]);
    }
}
