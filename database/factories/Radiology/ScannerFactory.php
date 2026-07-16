<?php

namespace Database\Factories\Radiology;

use App\Models\Radiology\Scanner;
use Database\Factories\Radiology\Concerns\CreatesRadiologyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Scanner> */
class ScannerFactory extends Factory
{
    use CreatesRadiologyFixtures;

    protected $model = Scanner::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $this->ensureRadiologyCatalogs();

        return [
            'scanner_uuid' => (string) Str::uuid(),
            'source_id' => fn (): int => $this->createIntegrationSourceId('ris'),
            'source_scanner_key' => 'scanner-'.Str::uuid(),
            'facility_id' => null,
            'unit_id' => null,
            'location_id' => null,
            'modality_code' => 'CT',
            'label' => fake()->randomElement(['CT 1', 'CT ED', 'MRI 1', 'Portable XR Pool']),
            'capacity' => 1,
            'status' => 'operational',
            'portable_capable' => false,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function modality(string $code): static
    {
        return $this->state(fn (): array => ['modality_code' => $code, 'portable_capable' => in_array($code, ['XR', 'US'], true)]);
    }
}
