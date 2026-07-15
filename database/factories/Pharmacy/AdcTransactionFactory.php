<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\AdcTransaction;
use App\Models\Pharmacy\MedicationOrder;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdcTransaction> */
class AdcTransactionFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = AdcTransaction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transaction_uuid' => (string) Str::uuid(),
            'adc_station_id' => fn (): int => (int) AdcStation::factory()->create()->adc_station_id,
            'source_id' => fn (array $attributes): int => (int) AdcStation::query()->findOrFail($attributes['adc_station_id'])->source_id,
            'source_transaction_key' => 'adc-txn-'.Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->create()->rx_order_id,
            'unit_id' => fn (array $attributes): ?int => AdcStation::query()->findOrFail($attributes['adc_station_id'])->unit_id,
            'transaction_type' => 'vend',
            'is_controlled' => false,
            'quantity' => 1,
            'discrepancy_key' => null,
            'occurred_at' => now()->subMinutes(10),
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function refill(): static
    {
        return $this->state(fn (): array => [
            'transaction_type' => 'refill',
            'rx_order_id' => null,
            'quantity' => 25,
        ]);
    }

    public function returnToStock(): static
    {
        return $this->state(fn (): array => ['transaction_type' => 'return']);
    }

    public function waste(): static
    {
        return $this->state(fn (): array => [
            'transaction_type' => 'waste',
            'is_controlled' => true,
            'quantity' => 0.5,
        ]);
    }

    public function override(): static
    {
        return $this->state(fn (): array => [
            'transaction_type' => 'override',
            'rx_order_id' => null,
            'is_controlled' => true,
        ]);
    }

    public function discrepancyOpen(?string $discrepancyKey = null): static
    {
        return $this->state(fn (): array => [
            'transaction_type' => 'discrepancy_open',
            'rx_order_id' => null,
            'is_controlled' => true,
            'quantity' => null,
            'discrepancy_key' => $discrepancyKey ?? 'discrepancy-'.Str::uuid(),
        ]);
    }

    public function discrepancyResolvedFor(AdcTransaction $open): static
    {
        return $this->state(fn (): array => [
            'adc_station_id' => $open->adc_station_id,
            'source_id' => $open->source_id,
            'unit_id' => $open->unit_id,
            'transaction_type' => 'discrepancy_resolved',
            'rx_order_id' => null,
            'is_controlled' => true,
            'quantity' => null,
            'discrepancy_key' => $open->discrepancy_key,
            'occurred_at' => now(),
        ]);
    }

    public function stockout(): static
    {
        return $this->state(fn (): array => [
            'transaction_type' => 'stockout',
            'rx_order_id' => null,
            'quantity' => null,
        ]);
    }
}
