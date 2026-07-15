<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\AdcStation;
use App\Models\Pharmacy\Dispense;
use App\Models\Pharmacy\MedicationOrder;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Dispense> */
class DispenseFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = Dispense::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'dispense_uuid' => (string) Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->create()->rx_order_id,
            'source_id' => fn (array $attributes): int => (int) MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->source_id,
            'source_dispense_key' => 'rx-dispense-'.Str::uuid(),
            'dispense_channel' => 'adc',
            'adc_station_id' => fn (): int => (int) AdcStation::factory()->create()->adc_station_id,
            'status' => 'dispensed',
            'delivery_method' => null,
            'dispensed_at' => now()->subMinutes(15),
            'delivered_at' => null,
            'returned_at' => null,
            'cancelled_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => 'delivered',
            'delivery_method' => 'pneumatic_tube',
            'delivered_at' => now()->subMinutes(5),
        ]);
    }

    public function ivRoom(): static
    {
        return $this->state(fn (): array => [
            'dispense_channel' => 'iv_room',
            'adc_station_id' => null,
            'delivery_method' => 'courier',
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn (): array => [
            'status' => 'returned',
            'returned_at' => now()->subMinutes(2),
        ]);
    }
}
