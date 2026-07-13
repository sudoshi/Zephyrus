<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\AdcStation;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdcStation> */
class AdcStationFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = AdcStation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'station_uuid' => (string) Str::uuid(),
            'source_id' => fn (): int => $this->createIntegrationSourceId('adc'),
            'source_station_key' => 'adc-station-'.Str::uuid(),
            'unit_id' => fn (): int => $this->createUnitId(),
            'label' => 'ADC '.Str::upper(Str::random(4)),
            'station_type' => 'general',
            'status' => 'operational',
            'is_profiled' => true,
            'controlled_capable' => true,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function anesthesia(): static
    {
        return $this->state(fn (): array => ['station_type' => 'anesthesia']);
    }

    public function downtime(): static
    {
        return $this->state(fn (): array => ['status' => 'downtime']);
    }
}
