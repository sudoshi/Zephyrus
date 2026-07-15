<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\Administration;
use App\Models\Pharmacy\MedicationOrder;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Administration> */
class AdministrationFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = Administration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $administered = now()->subHours(6);

        return [
            'administration_uuid' => (string) Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->create()->rx_order_id,
            'source_id' => fn (array $attributes): int => (int) MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->source_id,
            'source_administration_key' => 'rx-admin-'.Str::uuid(),
            'source_row_version' => '1',
            'import_batch_key' => 'bcma-extract-'.now()->format('Ymd').'-0500',
            'administration_source_class' => 'bcma_warehouse',
            'administration_status' => 'given',
            'administration_route' => 'oral',
            'administered_at' => $administered,
            'source_cutoff_at' => $administered->copy()->addHours(2),
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function held(): static
    {
        return $this->state(fn (): array => ['administration_status' => 'held']);
    }

    public function fromImportBatch(string $importBatchKey, mixed $cutoffAt = null): static
    {
        return $this->state(fn (): array => [
            'import_batch_key' => $importBatchKey,
            'source_cutoff_at' => $cutoffAt ?? now()->subHours(4),
        ]);
    }

    public function correctionOf(Administration $parent): static
    {
        return $this->state(fn (): array => [
            'rx_order_id' => $parent->rx_order_id,
            'source_id' => $parent->source_id,
            'source_administration_key' => $parent->source_administration_key,
            'source_row_version' => (string) (((int) ($parent->source_row_version ?? 1)) + 1),
            'import_batch_key' => $parent->import_batch_key.'-corrected',
            'administered_at' => $parent->administered_at,
            'source_cutoff_at' => now(),
        ]);
    }
}
