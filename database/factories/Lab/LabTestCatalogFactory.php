<?php

namespace Database\Factories\Lab;

use App\Models\Lab\LabTestCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LabTestCatalog> */
class LabTestCatalogFactory extends Factory
{
    protected $model = LabTestCatalog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'factory.lab.'.Str::lower(Str::random(12));

        return [
            'catalog_uuid' => (string) Str::uuid(),
            'catalog_key' => $key,
            'local_code' => Str::upper(Str::random(10)),
            'loinc_code' => null,
            'label' => 'Factory laboratory test',
            'department' => 'chemistry',
            'test_family' => 'factory_test',
            'expected_tat_class' => 'routine',
            'decision_class' => 'none',
            'specimen_type' => 'serum',
            'is_active' => true,
            'effective_from' => now()->startOfYear(),
            'effective_to' => null,
            'metadata' => ['data_origin' => 'factory'],
        ];
    }

    public function decisionClass(string $decisionClass): static
    {
        return $this->state(fn (): array => ['decision_class' => $decisionClass]);
    }
}
